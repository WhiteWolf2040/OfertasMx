<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveReadOnlyShareToken;
use App\Http\Resources\ReadOnlyApiResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReadOnlyApiController extends Controller
{
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

    private const PUBLIC_RESOURCES = [
        'categories',
        'deals',
        'comments',
        'votes',
    ];

    private const AUTHENTICATED_RESOURCES = [
        'robots',
        'robot-runs',
        'store-profiles',
        'robot-notifications',
        'users',
    ];

    public function resourcesIndex(Request $request): JsonResponse|Response
    {
        $token = $request->attributes->get(ResolveReadOnlyShareToken::REQUEST_ATTRIBUTE);
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $baseUrl = rtrim((string) config('app.url'), '/').'/api/v1';
        $links = [];

        foreach (self::SHAREABLE_RESOURCES as $resource) {
            $links[$resource] = $baseUrl.'/'.$resource.'?'.http_build_query(
                ['token' => $token],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        }

        if ($this->wantsHtml($request)) {
            $resources = [];

            foreach ($links as $resource => $url) {
                $resources[] = [
                    'name' => $this->resourceTitle($resource),
                    'url' => $url,
                    'safeUrl' => $this->safeHttpsUrl($url),
                ];
            }

            return $this->htmlResponse($request, 'api.resources', [
                'pageTitle' => 'Recursos de la API',
                'resources' => $resources,
            ]);
        }

        return response()->json($links)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function all(Request $request): JsonResponse|Response
    {
        $params = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $limit = (int) ($params['limit'] ?? 100);
        $offset = (int) ($params['offset'] ?? 0);
        $data = [];

        foreach (self::SHAREABLE_RESOURCES as $resource) {
            $query = $this->queryFor($resource, $request);
            $count = (clone $query)->count();
            $items = $query
                ->orderBy('id')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $data[$resource] = [
                'count' => $count,
                'results' => $items
                    ->map(fn (Model $item): array => (new ReadOnlyApiResource($item, $resource))->resolve($request))
                    ->values(),
                'next' => $offset + $limit < $count
                    ? $this->paginationLink($request, $limit, $offset + $limit)
                    : null,
            ];
        }

        if ($this->wantsHtml($request)) {
            $htmlResources = [];

            foreach ($data as $resource => $section) {
                $section['resourceKey'] = $resource;
                $section['title'] = $this->resourceTitle($resource);
                $section['items'] = $this->presentRecords($section['results'], $resource);
                $section['previous'] = $offset > 0
                    ? $this->paginationLink($request, $limit, max(0, $offset - $limit))
                    : null;
                $htmlResources[$resource] = $section;
            }

            return $this->htmlResponse($request, 'api.all', [
                'pageTitle' => 'Todos los recursos',
                'resources' => $htmlResources,
            ]);
        }

        return response()->json($data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function index(Request $request, string $resource): JsonResponse|Response
    {
        if (! $this->isKnownResource($resource)) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        $params = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $limit = (int) ($params['limit'] ?? 20);
        $offset = (int) ($params['offset'] ?? 0);
        $query = $this->queryFor($resource, $request);
        $count = (clone $query)->count();

        $items = $query
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $data = [
            'count' => $count,
            'next' => $offset + $limit < $count
                ? $this->paginationLink($request, $limit, $offset + $limit)
                : null,
            'previous' => $offset > 0
                ? $this->paginationLink($request, $limit, max(0, $offset - $limit))
                : null,
            'results' => $items
                ->map(fn (Model $item): array => (new ReadOnlyApiResource($item, $resource))->resolve($request))
                ->values(),
        ];

        if ($resource !== 'users' && $this->wantsHtml($request)) {
            return $this->htmlResponse($request, 'api.resource', [
                'pageTitle' => $this->resourceTitle($resource),
                'resourceTitle' => $this->resourceTitle($resource),
                'resource' => $resource,
                'count' => $count,
                'items' => $this->presentRecords($data['results'], $resource),
                'previous' => $data['previous'],
                'next' => $data['next'],
            ]);
        }

        return response()->json($data);
    }

    public function show(Request $request, string $id, string $resource): JsonResponse|Response
    {
        if (! $this->isKnownResource($resource)) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        $item = $this->queryFor($resource, $request)->whereKey((int) $id)->first();

        if ($item === null) {
            return response()->json(['message' => 'Registro no encontrado.'], 404);
        }

        $data = (new ReadOnlyApiResource($item, $resource))->resolve($request);

        if ($resource !== 'users' && $this->wantsHtml($request)) {
            return $this->htmlResponse($request, 'api.detail', [
                'pageTitle' => $this->resourceTitle($resource).' #'.$id,
                'resourceTitle' => $this->resourceTitle($resource),
                'resource' => $resource,
                'item' => $this->presentRecords([$data], $resource)[0],
            ]);
        }

        return response()->json($data);
    }

    private function isKnownResource(string $resource): bool
    {
        return in_array($resource, self::PUBLIC_RESOURCES, true)
            || in_array($resource, self::AUTHENTICATED_RESOURCES, true);
    }

    private function paginationLink(Request $request, int $limit, int $offset): string
    {
        $query = array_merge($request->query(), [
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $shareToken = $request->attributes->get(ResolveReadOnlyShareToken::REQUEST_ATTRIBUTE);

        if (is_string($shareToken) && $shareToken !== '') {
            $query['token'] = $shareToken;
        }

        return $request->url().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function wantsHtml(Request $request): bool
    {
        $format = $request->query('format');
        if ($format === 'html') {
            return true;
        }

        if ($format === 'json') {
            return false;
        }

        return in_array('text/html', $request->getAcceptableContentTypes(), true)
            && $request->prefers(['text/html', 'application/json']) === 'text/html';
    }

    private function htmlResponse(Request $request, string $view, array $data): Response
    {
        $data['jsonUrl'] = $this->formatUrl($request, 'json');

        return response()->view($view, $data)
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex')
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; img-src https:; style-src 'unsafe-inline'; script-src 'none'"
            )
            ->header('Vary', 'Accept');
    }

    private function formatUrl(Request $request, string $format): string
    {
        $query = $request->query();
        $query['format'] = $format;
        $shareToken = $request->attributes->get(ResolveReadOnlyShareToken::REQUEST_ATTRIBUTE);

        if (is_string($shareToken) && $shareToken !== '') {
            $query['token'] = $shareToken;
        }

        return $request->url().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function presentRecords(iterable $records, string $resource): array
    {
        $presented = [];

        foreach ($records as $record) {
            $primary = match ($resource) {
                'categories' => $record['name'] ?? null,
                'deals' => $record['title'] ?? null,
                'comments' => $record['body'] ?? null,
                'votes' => $record['value'] ?? null,
                'robots' => $record['name'] ?? null,
                'robot-runs' => $record['status'] ?? null,
                'store-profiles' => $record['domain'] ?? null,
                'robot-notifications' => $record['label'] ?? $record['type'] ?? null,
                default => null,
            };
            $price = $record['price'] ?? $record['last_price'] ?? null;
            $currency = $record['currency'] ?? $record['last_currency'] ?? null;
            $priceText = $this->displayValue($price);
            if ($priceText !== '—' && is_scalar($currency) && (string) $currency !== '') {
                $priceText .= ' '.$currency;
            }

            $store = $record['store'] ?? null;
            if ($store === null && $resource === 'robots' && is_string($record['url'] ?? null)) {
                $store = parse_url($record['url'], PHP_URL_HOST);
            }

            $status = $record['last_status'] ?? $record['status'] ?? null;
            if ($status === null && array_key_exists('is_active', $record)) {
                $status = $record['is_active'] ? 'Activo' : 'Pausado';
            }

            $image = $record['image'] ?? (
                is_array($record['overrides'] ?? null)
                    ? ($record['overrides']['image'] ?? null)
                    : null
            );

            $presented[] = [
                'id' => $this->displayValue($record['id'] ?? null),
                'primary' => $this->displayValue($primary),
                'name' => $this->displayValue($record['title'] ?? $record['name'] ?? $primary),
                'price' => $priceText,
                'store' => $this->displayValue($store),
                'status' => $this->displayValue($status),
                'date' => $this->displayValue($record['last_run_at'] ?? $record['created_at'] ?? null),
                'imageUrl' => $this->safeHttpsUrl($image),
                'externalUrl' => $this->safeHttpsUrl($record['url'] ?? null),
                'detailsJson' => json_encode(
                    $record,
                    JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_INVALID_UTF8_SUBSTITUTE
                        | JSON_THROW_ON_ERROR
                ),
            ];
        }

        return $presented;
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        return is_scalar($value) ? (string) $value : '—';
    }

    private function safeHttpsUrl(mixed $url): ?string
    {
        if (
            ! is_string($url)
            || ! preg_match('/\Ahttps:\/\//i', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        return $url;
    }

    private function resourceTitle(string $resource): string
    {
        return match ($resource) {
            'categories' => 'Categorías',
            'deals' => 'Ofertas',
            'comments' => 'Comentarios',
            'votes' => 'Votos',
            'robots' => 'Robots',
            'robot-runs' => 'Ejecuciones de robots',
            'store-profiles' => 'Perfiles de tienda',
            'robot-notifications' => 'Notificaciones de robots',
            default => 'Recurso',
        };
    }

    private function queryFor(string $resource, Request $request): Builder
    {
        $model = ReadOnlyApiResource::definition($resource)['model'];
        $query = $model::query();

        if (in_array($resource, self::PUBLIC_RESOURCES, true)) {
            if ($resource === 'deals') {
                $query->withSum('votes', 'value');
            }

            return $query;
        }

        $userId = $request->user()->getKey();

        if ($resource === 'users') {
            return $query;
        }

        if ($resource === 'robot-runs') {
            return $query->whereHas(
                'robot',
                static fn (Builder $robots) => $robots->where('user_id', $userId)
            );
        }

        return $query->where('user_id', $userId);
    }
}
