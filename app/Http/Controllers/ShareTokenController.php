<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShareTokenController extends Controller
{
    private const RESOURCES = [
        'categories',
        'deals',
        'comments',
        'votes',
        'robots',
        'robot-runs',
        'store-profiles',
        'robot-notifications',
        'users',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $expiresAt = now()->addMinutes(15);
        $newToken = $request->user()->createToken(
            'read-only-share',
            ['read-only'],
            $expiresAt
        );
        $plainTextToken = $newToken->plainTextToken;
        $baseUrl = rtrim((string) config('app.url'), '/').'/api/v1';
        $links = [];

        foreach (self::RESOURCES as $resource) {
            $links[$resource] = $baseUrl.'/'.$resource.'?'.http_build_query([
                'token' => $plainTextToken,
            ]);
        }

        return response()->json([
            'token' => $plainTextToken,
            'expires_at' => $newToken->accessToken->expires_at->toISOString(),
            'base_url' => $baseUrl,
            'links' => $links,
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
