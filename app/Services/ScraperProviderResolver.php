<?php

namespace App\Services;

class ScraperProviderResolver
{
    public function resolve(?string $only = null): array
    {
        $providers = [
            'http' => HttpProvider::class,
            'browser' => BrowserProvider::class,
            'api' => ApiProvider::class,
        ];

        $chain = $only === null
            ? config('scraper.chain', ['http', 'browser'])
            : [$only];
        $resolved = [];
        foreach ((array) $chain as $name) {
            if (! is_string($name) || ! isset($providers[$name])) {
                throw new \InvalidArgumentException('Proveedor de scraper no configurado: '.(string) $name);
            }

            $resolved[] = app($providers[$name]);
        }

        return $resolved;
    }
}
