<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class BrowserProvider implements ScraperProvider
{
    public function __construct(
        private readonly PublicUrlValidator $urlValidator,
        private readonly ScrapeUrlNormalizer $urlNormalizer,
    ) {}

    public function name(): string
    {
        return 'browser';
    }

    public function fetch(string $url): ?ScraperPage
    {
        return $this->fetchPage($url, false);
    }

    public function fetchWithCapture(string $url): ?ScraperPage
    {
        return $this->fetchPage($url, true);
    }

    private function fetchPage(string $url, bool $capture): ?ScraperPage
    {
        $url = $this->urlNormalizer->normalize($url);
        $this->urlValidator->validate($url);
        $lock = $this->acquireBrowserSlot();

        try {
            $script = (string) config('scraper.browser.script');
            $process = new Process(
                [config('scraper.browser.node_binary', 'node'), $script],
                base_path(),
                timeout: 25,
            );
            $process->setInput(json_encode([
                'url' => $url,
                'headless' => (bool) config('scraper.browser.headless', false),
                'channel' => config('scraper.browser.channel'),
                'capture' => $capture,
            ], JSON_THROW_ON_ERROR));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($result) || ! is_string($result['html'] ?? null) || ! is_string($result['url'] ?? null)) {
                throw new \UnexpectedValueException('El navegador devolvió una respuesta no válida.');
            }

            $finalUrl = $this->urlNormalizer->normalize($result['url']);
            $this->urlValidator->validate($finalUrl);
            if (strlen($result['html']) > 5_000_000) {
                throw new \UnexpectedValueException('La página renderizada excede el tamaño permitido.');
            }

            return new ScraperPage(
                $result['html'],
                $finalUrl,
                (int) ($result['status'] ?? 200),
                isset($result['screenshot']) ? base64_decode($result['screenshot'], true) ?: null : null,
            );
        } finally {
            $lock->release();
        }
    }

    private function acquireBrowserSlot(): Lock
    {
        $deadline = microtime(true) + 5;
        do {
            $slots = max(1, min(2, (int) config('scraper.browser.concurrency', 2)));
            foreach (range(1, $slots) as $slot) {
                $lock = Cache::lock('scraper:browser:slot:'.$slot, 30);
                if ($lock->get()) {
                    return $lock;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new LockTimeoutException('No hay un espacio de navegador disponible.');
    }
}
