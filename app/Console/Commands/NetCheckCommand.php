<?php

namespace App\Console\Commands;

use App\Services\HttpProvider;
use App\Services\PublicUrlValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class NetCheckCommand extends Command
{
    protected $signature = 'net:check';

    protected $description = 'Diagnostica DNS, validación SSRF y conectividad HTTP de tiendas';

    public function handle(PublicUrlValidator $urlValidator): int
    {
        foreach ([
            'https://example.com',
            'https://www.bodegaaurrera.com.mx',
            'https://www.walmart.com.mx',
        ] as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $ipv4 = @gethostbynamel($host);
            $aaaaRecords = @dns_get_record($host, DNS_AAAA);
            $ipv6 = is_array($aaaaRecords)
                ? array_values(array_filter(array_column($aaaaRecords, 'ipv6')))
                : [];

            $this->line("URL: {$url}");
            $this->line('gethostbynamel IPv4: '.($ipv4 === false ? 'sin registros' : implode(', ', $ipv4)));
            $this->line('DNS AAAA: '.($ipv6 === [] ? 'sin registros' : implode(', ', $ipv6)));

            try {
                $validatedAddresses = $urlValidator->validate($url);
                $chosenIpv4 = collect($validatedAddresses)
                    ->first(static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
                $this->line('IP elegida por el validador SSRF: '.($chosenIpv4 ?? 'sin IPv4 pública'));
            } catch (Throwable $exception) {
                $chosenIpv4 = null;
                $this->line(sprintf(
                    'IP elegida por el validador SSRF: ERROR %s: %s',
                    $exception::class,
                    $exception->getMessage(),
                ));
            }

            $this->line('HTTP cliente actual: '.$this->requestResult($url));
            if ($chosenIpv4 === null) {
                $this->line('HTTP forzando IPv4: omitido (el validador no aprobó una IPv4 pública).');
            } else {
                $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
                $options = [
                    'allow_redirects' => false,
                    'proxy' => '',
                    'curl' => HttpProvider::ipv4CurlOptions() + [
                        CURLOPT_RESOLVE => ["{$host}:{$port}:{$chosenIpv4}"],
                    ],
                ];
                $this->line('HTTP forzando IPv4: '.$this->requestResult($url, $options));
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function requestResult(string $url, array $options = []): string
    {
        try {
            $response = Http::timeout(12)
                ->withOptions(['allow_redirects' => false, 'proxy' => ''] + $options)
                ->get($url);

            return 'HTTP '.$response->status();
        } catch (Throwable $exception) {
            return sprintf('ERROR %s: %s', $exception::class, $exception->getMessage());
        }
    }
}
