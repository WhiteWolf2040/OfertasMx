<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProductPageScraper
{
    public function __construct(
        private readonly PublicUrlValidator $urlValidator,
        private readonly ProductMetadataExtractor $extractor,
        private readonly ScrapeUrlNormalizer $urlNormalizer,
        private readonly ScraperProviderResolver $providerResolver,
    ) {}

    public function scrape(string $url): array
    {
        $normalizedUrl = $this->urlNormalizer->normalize($url);
        $this->urlValidator->validate($normalizedUrl);
        $cacheKey = 'product-scrape:v5:'.hash('sha256', $normalizedUrl);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->scrapeUncached($normalizedUrl);
        unset($result['_diagnostics']);
        if (! $result['blocked']) {
            Cache::put($cacheKey, $result, (int) config('scraper.cache_seconds', 3600));
        }

        return $result;
    }

    public function scrapeDetailed(string $url, ?string $provider = null, bool $save = false): array
    {
        $normalizedUrl = $this->urlNormalizer->normalize($url);
        $this->urlValidator->validate($normalizedUrl);

        // Ya no se fuerza "browser" con --save: se guarda el HTML de cada proveedor intentado.
        return $this->scrapeUncached($normalizedUrl, $provider, $save);
    }

    private function scrapeUncached(string $url, ?string $onlyProvider = null, bool $capture = false): array
    {
        $startedAt = microtime(true);
        $storeHost = preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST)) ?: null;
        $metadata = [
            'title' => null,
            'description' => null,
            'image' => null,
            'price' => null,
            'currency' => null,
            'store' => $storeHost,
            'blocked' => false,
            'provider' => null,
        ];
        $attempts = [];
        $fieldStages = ['store' => 'url_host'];
        $fieldSources = ['store' => [
            'stage' => 'url_host',
            'selector' => 'URL host',
            'raw' => $storeHost,
        ]];
        $priceCandidates = [];
        $htmlBytes = 0;
        $savedHtml = null;
        $screenshot = null;

        $providers = $this->providerResolver->resolve($onlyProvider);
        $providerNames = array_map(static fn (ScraperProvider $provider): string => $provider->name(), $providers);
        if ($onlyProvider === null && end($providerNames) !== 'browser') {
            $providers[] = app(BrowserProvider::class);
        }

        foreach ($providers as $provider) {
            $providerStartedAt = microtime(true);
            $attempt = [
                'provider' => $provider->name(),
                'elapsed_ms' => 0,
                'blocked' => false,
                'reason' => null,
                'error' => null,
                'http_status' => null,
                'html_bytes' => 0,
                'fields_found' => [],
                'saved' => [],
            ];

            try {
                $page = $capture && $provider instanceof BrowserProvider
                    ? $provider->fetchWithCapture($url)
                    : $provider->fetch($url);
                if ($page === null) {
                    $attempt['error'] = 'El proveedor no devolvió HTML.';
                    $attempt['elapsed_ms'] = (int) ((microtime(true) - $providerStartedAt) * 1000);
                    $attempts[] = $attempt;

                    continue;
                }

                $finalUrl = $this->urlNormalizer->normalize($page->finalUrl);
                $this->urlValidator->validate($finalUrl);
                if ($page->status >= 400 && ! in_array($page->status, [403, 429, 503], true)) {
                    $attempt['http_status'] = $page->status;
                    $attempt['html_bytes'] = strlen($page->html);
                    $attempt['error'] = 'HTTP '.$page->status;
                    $attempt['elapsed_ms'] = (int) ((microtime(true) - $providerStartedAt) * 1000);
                    $attempts[] = $attempt;
                    Log::warning('Product scraper provider failed, trying next', $attempt);

                    continue;
                }

                $stages = [];
                $sources = [];
                $candidates = [];
                $extracted = $this->extractor->extract($page->html, $finalUrl, $stages, $sources, $candidates);
                $htmlSize = strlen($page->html);
                $htmlBytes = $htmlSize;
                $hasJsonLd = preg_match('/<script\b[^>]*type\s*=\s*["\'][^"\']*ld\+json/i', $page->html) === 1;
                $visibleText = $this->visibleText($page->html);
                $signals = $this->blockingSignals($page->status, $visibleText, $htmlSize, $hasJsonLd, $extracted['title']);

                // Con título y precio extraídos la página es real: la señal se ignora.
                $hasData = $extracted['title'] !== null && $extracted['price'] !== null;
                $isBlocked = ! $hasData && $signals !== [];

                $attempt['http_status'] = $page->status;
                $attempt['html_bytes'] = $htmlSize;
                $attempt['blocked'] = $isBlocked;
                $attempt['reason'] = $signals === [] ? null : implode(', ', $signals);
                $attempt['fields_found'] = array_keys(array_filter(
                    $extracted,
                    static fn ($value) => $value !== null
                ));

                if ($capture) {
                    $dir = storage_path('app/debug');
                    if (! is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                    $base = $dir.DIRECTORY_SEPARATOR.date('Ymd_His').'_'.$provider->name();
                    file_put_contents($base.'.html', $page->html);
                    $attempt['saved'][] = $base.'.html';
                    if (! empty($page->screenshot)) {
                        file_put_contents($base.'.png', $page->screenshot);
                        $attempt['saved'][] = $base.'.png';
                    }
                    $savedHtml = $page->html;
                    $screenshot = $page->screenshot ?? null;
                }

                if ($isBlocked) {
                    $attempt['elapsed_ms'] = (int) ((microtime(true) - $providerStartedAt) * 1000);
                    $attempts[] = $attempt;
                    Log::info('Product scraper provider blocked, trying next', $attempt);

                    continue;
                }

                foreach (['title', 'description', 'image', 'price', 'currency'] as $field) {
                    if ($metadata[$field] === null && $extracted[$field] !== null) {
                        $metadata[$field] = $extracted[$field];
                        $fieldStages[$field] = $stages[$field] ?? 'unknown';
                        $fieldSources[$field] = $sources[$field] ?? [
                            'stage' => $fieldStages[$field],
                            'selector' => null,
                            'raw' => $extracted[$field],
                        ];
                    }
                }
                if ($candidates !== []) {
                    $priceCandidates = $candidates;
                }
                $metadata['provider'] = $provider->name();
                $metadata['store'] = preg_replace('/^www\./i', '', (string) parse_url($finalUrl, PHP_URL_HOST))
                    ?: $metadata['store'];
                if ($metadata['store'] !== $storeHost) {
                    $fieldStages['store'] = 'final_url_host';
                    $fieldSources['store'] = [
                        'stage' => 'final_url_host',
                        'selector' => 'final URL host',
                        'raw' => $metadata['store'],
                    ];
                }
                $attempt['elapsed_ms'] = (int) ((microtime(true) - $providerStartedAt) * 1000);
                $attempts[] = $attempt;
                Log::info('Product scraper provider completed', $attempt);

                if ($metadata['title'] !== null && $metadata['price'] !== null) {
                    break;
                }
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $attempt['error'] = $exception->getMessage();
                $attempt['elapsed_ms'] = (int) ((microtime(true) - $providerStartedAt) * 1000);
                $attempts[] = $attempt;
                Log::warning('Product scraper provider failed, trying next', $attempt);
            }
        }

        $complete = $metadata['title'] !== null && $metadata['price'] !== null;
        $metadata['blocked'] = ! $complete;

        $elapsed = (int) ((microtime(true) - $startedAt) * 1000);
        Log::info('Product page scrape finished', [
            'provider' => $metadata['provider'],
            'blocked' => $metadata['blocked'],
            'elapsed_ms' => $elapsed,
        ]);
        $metadata['_diagnostics'] = [
            'provider' => $metadata['provider'],
            'elapsed_ms' => $elapsed,
            'html_bytes' => $htmlBytes,
            'fields_found' => array_keys(array_filter(
                array_intersect_key($metadata, array_flip(['title', 'description', 'image', 'price', 'currency'])),
                static fn ($value) => $value !== null
            )),
            'field_stages' => $fieldStages,
            'field_sources' => $fieldSources,
            'price_candidates' => $priceCandidates,
            'attempts' => $attempts,
            'html' => $capture ? $savedHtml : null,
            'screenshot' => $capture ? $screenshot : null,
        ];

        return $metadata;
    }

    /**
     * Texto realmente visible: sin <script>, <style>, <noscript> ni <template>,
     * para que el JSON y los scripts inline no disparen falsos positivos.
     */
    private function visibleText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function blockingSignals(int $status, string $text, int $htmlSize, bool $hasJsonLd, ?string $title): array
    {
        $signals = [];
        if (in_array($status, [403, 429, 503], true)) {
            $signals[] = 'HTTP '.$status;
        }
        foreach ([
            'captcha' => '/captcha/iu',
            'robot check' => '/robot\s*check/iu',
            'verificación humana' => '/verifica(?:\s+que\s+eres\s+humano|\s+que\s+no\s+eres\s+un\s+robot)|verificaci[oó]n humana|verify\s+you\s+are\s+human/iu',
            'account-verification' => '/account-verification/iu',
            'access denied' => '/access\s*denied/iu',
            'just a moment' => '/just\s+a\s+moment/iu',
            'error de acceso de la tienda' => '/hubo\s+un\s+error\s+accediendo\s+a\s+esta\s+p[aá]gina/iu',
        ] as $reason => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $signals[] = $reason;
            }
        }
        if ($htmlSize < 40 * 1024 && ! $hasJsonLd && $title === null) {
            $signals[] = 'HTML corto sin JSON-LD ni título';
        }

        return $signals;
    }
}
