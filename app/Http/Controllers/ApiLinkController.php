<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class ApiLinkController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return $this->issueLink($request, false);
    }

    public function regenerate(Request $request): JsonResponse
    {
        return $this->issueLink($request, true);
    }

    public function destroy(Request $request): Response
    {
        DB::transaction(function () use ($request): void {
            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($request->user()->getKey());

            $user->tokens()->where('name', 'api-link')->delete();
            $user->forceFill(['api_link_token' => null])->save();
        });

        return response()->noContent()
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function issueLink(Request $request, bool $regenerate): JsonResponse
    {
        return DB::transaction(function () use ($request, $regenerate): JsonResponse {
            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($request->user()->getKey());

            $plainTextToken = $user->api_link_token;
            if (! $regenerate && is_string($plainTextToken) && $plainTextToken !== '') {
                $tokenModel = Sanctum::personalAccessTokenModel();
                $storedToken = $tokenModel::findToken($plainTextToken);

                if ($this->isCurrentApiLink($storedToken, $user)) {
                    $user->tokens()
                        ->where('name', 'api-link')
                        ->where($storedToken->getKeyName(), '!=', $storedToken->getKey())
                        ->delete();

                    return $this->linkResponse($plainTextToken);
                }
            }

            $user->tokens()->where('name', 'api-link')->delete();
            $newToken = $user->createToken('api-link', ['read-only']);
            $user->forceFill(['api_link_token' => $newToken->plainTextToken])->save();

            return $this->linkResponse($newToken->plainTextToken);
        });
    }

    private function isCurrentApiLink(?PersonalAccessToken $token, User $user): bool
    {
        return $token !== null
            && $token->name === 'api-link'
            && $token->tokenable_type === $user->getMorphClass()
            && (string) $token->tokenable_id === (string) $user->getKey()
            && $token->abilities === ['read-only']
            && $token->expires_at === null;
    }

    private function linkResponse(string $plainTextToken): JsonResponse
    {
        $baseUrl = rtrim((string) config('app.url'), '/').'/api/v1/all';
        $link = $baseUrl.'?'.http_build_query(
            ['token' => $plainTextToken],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return response()->json([
            'link' => $link,
            'token' => $plainTextToken,
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
