<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveReadOnlyShareToken;
use App\Http\Resources\ReadOnlyApiResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function resourcesIndex(Request $request): JsonResponse
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

        return response()->json($links)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function all(Request $request): JsonResponse
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

        return response()->json($data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function index(Request $request, string $resource): JsonResponse
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

        return response()->json([
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
        ]);
    }

    public function show(Request $request, string $id, string $resource): JsonResponse
    {
        if (! $this->isKnownResource($resource)) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        $item = $this->queryFor($resource, $request)->whereKey((int) $id)->first();

        if ($item === null) {
            return response()->json(['message' => 'Registro no encontrado.'], 404);
        }

        return response()->json((new ReadOnlyApiResource($item, $resource))->resolve($request));
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
