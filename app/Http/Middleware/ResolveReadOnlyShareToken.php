<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class ResolveReadOnlyShareToken
{
    public const REQUEST_ATTRIBUTE = 'api_share_token';

    private const SHAREABLE_RESOURCES = [
        'categories',
        'deals',
        'comments',
        'votes',
        'robots',
        'robot-runs',
        'store-profiles',
        'robot-notifications',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has('token')) {
            $safeQuery = $request->query->all();
            $queryToken = $safeQuery['token'] ?? null;
            unset($safeQuery['token']);
            $this->removeTokenFromRequestUri($request, $safeQuery);

            if (
                ! $this->isShareableGetRequest($request)
                || ! is_string($queryToken)
                || $queryToken === ''
            ) {
                return $this->unauthenticated();
            }

            $bearerToken = $request->bearerToken();
            if ($bearerToken !== null && ! hash_equals($bearerToken, $queryToken)) {
                return $this->unauthenticated();
            }

            $accessToken = $this->findToken($queryToken);
            if (! $this->isValidShareToken($accessToken)) {
                return $this->unauthenticated();
            }

            $request->attributes->set(self::REQUEST_ATTRIBUTE, $queryToken);
            $injectedAuthorization = $bearerToken === null;

            if ($injectedAuthorization) {
                $request->headers->set('Authorization', 'Bearer '.$queryToken);
            }

            try {
                $response = $next($request);
            } finally {
                if ($injectedAuthorization) {
                    $request->headers->remove('Authorization');
                }
            }

            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        $isApiIndex = $request->isMethod('GET')
            && trim($request->path(), '/') === 'api/v1';
        $isApiAll = $request->isMethod('GET')
            && trim($request->path(), '/') === 'api/v1/all';
        $isVersionedApiRequest = $request->is('api/v1') || $request->is('api/v1/*');
        $mustInspectBearer = ! $request->isMethod('GET') || $isVersionedApiRequest;

        if (! $mustInspectBearer) {
            return $next($request);
        }

        $bearerToken = $request->bearerToken();
        $accessToken = $bearerToken === null ? null : $this->findToken($bearerToken);
        $isReadOnly = $accessToken !== null
            && in_array('read-only', $accessToken->abilities, true);

        if (($isApiIndex || $isApiAll) && ! $this->isValidShareToken($accessToken)) {
            return $this->unauthenticated();
        }

        if ($isReadOnly) {
            if (
                ! $this->isValidShareToken($accessToken)
                || ! $this->isShareableGetRequest($request)
            ) {
                return $this->unauthenticated();
            }

            $request->attributes->set(self::REQUEST_ATTRIBUTE, $bearerToken);
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        return $next($request);
    }

    private function isShareableGetRequest(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        $segments = explode('/', trim($request->path(), '/'));
        if (in_array($segments, [['api', 'v1'], ['api', 'v1', 'all']], true)) {
            return true;
        }

        return count($segments) >= 3
            && $segments[0] === 'api'
            && $segments[1] === 'v1'
            && in_array($segments[2], self::SHAREABLE_RESOURCES, true)
            && (
                count($segments) === 3
                || (count($segments) === 4 && ctype_digit($segments[3]))
            );
    }

    private function findToken(string $plainTextToken): ?PersonalAccessToken
    {
        if (strlen($plainTextToken) > 512) {
            return null;
        }

        $model = Sanctum::personalAccessTokenModel();

        return $model::findToken($plainTextToken);
    }

    private function isValidShareToken(?PersonalAccessToken $accessToken): bool
    {
        if (
            $accessToken === null
            || $accessToken->abilities !== ['read-only']
            || ($accessToken->expires_at !== null && $accessToken->expires_at->isPast())
        ) {
            return false;
        }

        if ($accessToken->name === 'api-link' && $accessToken->expires_at === null) {
            return true;
        }

        $expiration = config('sanctum.expiration');

        return $expiration === null
            || $accessToken->created_at->gt(now()->subMinutes($expiration));
    }

    private function removeTokenFromRequestUri(Request $request, array $safeQuery): void
    {
        $queryString = http_build_query($safeQuery);
        $requestUri = $request->getPathInfo().($queryString === '' ? '' : '?'.$queryString);
        $server = $request->server->all();
        $headers = $request->headers->all();

        $server['QUERY_STRING'] = $queryString;
        $server['REQUEST_URI'] = $requestUri;

        $request->initialize(
            $safeQuery,
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server,
            $request->getContent()
        );
        $request->headers->replace($headers);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
