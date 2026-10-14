<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ApiProvider implements ScraperProvider
{
    public function fetch(string $url): ?ScraperPage
    {
        $endpoint = config('scraper.api.url');
        if (! is_string($endpoint) || trim($endpoint) === '') {
            return null;
        }

        $request = Http::timeout(25)->acceptJson();
        $key = config('scraper.api.key');
        if (is_string($key) && $key !== '') {
            $request = $request->withToken($key);
        }

        $response = $request->get($endpoint, [
            (string) config('scraper.api.url_parameter', 'url') => $url,
        ])->throw();
        $data = $response->json();

        if (! is_array($data) || ! is_string($data['html'] ?? null)) {
            throw new \UnexpectedValueException('El proveedor API no devolvió HTML.');
        }

        return new ScraperPage(
            $data['html'],
            is_string($data['final_url'] ?? null) ? $data['final_url'] : $url,
            (int) ($data['status'] ?? 200),
        );
    }

    public function name(): string
    {
        return 'api';
    }
}
