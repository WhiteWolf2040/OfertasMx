<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HttpProvider implements ScraperProvider
{
    public function __construct(
        private readonly PublicUrlValidator $urlValidator,
        private readonly ScrapeUrlNormalizer $urlNormalizer,
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function fetch(string $url): ?ScraperPage
    {
        $currentUrl = $this->urlNormalizer->normalize($url);

        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $addresses = $this->urlValidator->validate($currentUrl);
            $parts = parse_url($currentUrl);
            $host = trim((string) ($parts['host'] ?? ''), '[]');
            $curlOptions = self::ipv4CurlOptions();
            $httpOptions = [
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => $curlOptions,
            ];

            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                $port = $parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80);
                $ipv4Addresses = array_values(array_filter(
                    $addresses,
                    static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
                ));
                if ($ipv4Addresses === []) {
                    throw new HttpException(502, 'La tienda no tiene una dirección IPv4 pública disponible.');
                }
                $httpOptions['curl'][CURLOPT_RESOLVE] = array_map(
                    static fn (string $address) => $host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address),
                    $ipv4Addresses
                );
            }

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'es-MX,es;q=0.9,en;q=0.8',
                    'Referer' => 'https://www.google.com/',
                ])
                    ->timeout(10)
                    ->withOptions($httpOptions)
                    ->get($currentUrl);
            } catch (ConnectionException $exception) {
                $cause = $exception->getPrevious() ?? $exception;
                Log::warning('HTTP provider connection failed', [
                    'provider' => $this->name(),
                    'destination_host' => $host,
                    'exception_class' => $cause::class,
                    'exception_message' => $cause->getMessage(),
                    'connection_exception_class' => $exception::class,
                ]);
                throw new HttpException(502, 'No fue posible conectar con la tienda.', $exception);
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                Log::info('Product page redirected', [
                    'provider' => $this->name(),
                    'http_status' => $response->status(),
                    'html_bytes' => strlen($response->body()),
                    'blocked' => false,
                    'field_stages' => [],
                ]);
                $location = $response->header('Location');
                if (! is_string($location) || trim($location) === '' || $redirects === 5) {
                    throw new HttpException(502, 'La tienda respondió con una redirección no válida.');
                }

                try {
                    $currentUrl = $this->urlNormalizer->normalize(
                        (string) UriResolver::resolve(new Uri($currentUrl), new Uri(trim($location)))
                    );
                } catch (\InvalidArgumentException $exception) {
                    throw new HttpException(502, 'La tienda respondió con una redirección no válida.', $exception);
                }

                continue;
            }

            if (strlen($response->body()) > 5_000_000) {
                throw new HttpException(502, 'La página del producto excede el tamaño permitido.');
            }

            return new ScraperPage($response->body(), $currentUrl, $response->status());
        }

        throw new HttpException(502, 'La tienda excedió el límite de redirecciones.');
    }

    /**
     * @return array<int, int>
     */
    public static function ipv4CurlOptions(): array
    {
        return [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
    }
}
