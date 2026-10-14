<?php

namespace Tests\Feature;

use App\Services\BrowserProvider;
use App\Services\HttpProvider;
use App\Services\ProductPageScraper;
use App\Services\PublicUrlValidator;
use App\Services\ScraperPage;
use App\Services\ScraperProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductScraperFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_fallback_completes_missing_fields_and_preserves_http_values(): void
    {
        config()->set('scraper.chain', ['http']);
        $this->mock(PublicUrlValidator::class)
            ->shouldReceive('validate')
            ->atLeast()
            ->once()
            ->andReturn([]);

        $calls = [];
        $this->app->instance(HttpProvider::class, $this->provider(
            'http',
            '<script type="application/ld+json">{"@type":"Product","name":"Producto HTTP"}</script>',
            $calls
        ));
        $this->app->instance(BrowserProvider::class, $this->provider(
            'browser',
            '<script type="application/ld+json">{"@type":"Product","offers":{"price":"1.299,00","priceCurrency":"MXN"}}</script>',
            $calls
        ));

        $result = app(ProductPageScraper::class)->scrapeDetailed('https://shop.example/product');

        $this->assertSame('Producto HTTP', $result['title']);
        $this->assertSame('1299.00', $result['price']);
        $this->assertSame('browser', $result['provider']);
        $this->assertFalse($result['blocked']);
        $this->assertSame(['http', 'browser'], $calls);
    }

    private function provider(string $name, string $html, array &$calls): ScraperProvider
    {
        return new class($name, $html, $calls) implements ScraperProvider
        {
            public function __construct(
                private readonly string $providerName,
                private readonly string $html,
                private array &$calls,
            ) {}

            public function name(): string
            {
                return $this->providerName;
            }

            public function fetch(string $url): ?ScraperPage
            {
                $this->calls[] = $this->providerName;

                return new ScraperPage($this->html, $url, 200);
            }
        };
    }
}
