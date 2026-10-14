<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PublicUrlValidator
{
    public function validate(string $url): array
    {
        $parts = parse_url($url);

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== (strtolower($parts['scheme']) === 'https' ? 443 : 80))
        ) {
            $this->invalidUrl();
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));

        if (
            $host === 'localhost'
            || ! str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false
            || preg_match('/\.(?:localhost|local|internal|test)$/i', $host) === 1
        ) {
            $this->invalidUrl();
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolveHost($host);

        if ($addresses === []) {
            $this->invalidUrl();
        }

        foreach ($addresses as $address) {
            if (
                filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                $this->invalidUrl();
            }
        }

        return $addresses;
    }

    public function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            $ipv4Addresses = @gethostbynamel($host);
            if (is_array($ipv4Addresses)) {
                $addresses = $ipv4Addresses;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function invalidUrl(): never
    {
        throw ValidationException::withMessages([
            'url' => 'La URL debe apuntar a un sitio público HTTP o HTTPS.',
        ]);
    }
}
