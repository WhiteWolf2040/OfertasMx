<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\User;
use App\Models\Vote;
use App\Services\BrowserProvider;
use App\Services\HttpProvider;
use App\Services\PublicUrlValidator;
use App\Services\ScraperPage;
use App\Services\ScraperProvider;
use App\Services\ScrapeUrlNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ScrapeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
        $this->app->instance(BrowserProvider::class, new class implements ScraperProvider
        {
            public function name(): string
            {
                return 'browser';
            }

            public function fetch(string $url): ?ScraperPage
            {
                return null;
            }
        });
    }

    public function test_it_extracts_product_data_from_json_ld(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response(
                <<<'HTML'
                <html><head>
                    <script type="application/ld+json">
                        {"@type":"Product","name":"Audífonos","description":"Cancelación de ruido","image":"/images/headphones.jpg","offers":{"price":"1,299.00","priceCurrency":"MXN"}}
                    </script>
                </head><body></body></html>
                HTML,
                200
            ),
        ]);

        $response = $this->postJson('/api/scrape', ['url' => 'https://shop.example/product/1']);

        $response->assertOk()->assertExactJson([
            'title' => 'Audífonos',
            'description' => 'Cancelación de ruido',
            'image' => 'https://shop.example/images/headphones.jpg',
            'price' => '1299.00',
            'currency' => 'MXN',
            'store' => 'shop.example',
            'blocked' => false,
            'provider' => 'http',
        ]);

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent')
            && $request->hasHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
            && $request->hasHeader('Accept-Language', 'es-MX,es;q=0.9,en;q=0.8')
            && $request->hasHeader('Referer', 'https://www.google.com/'));
    }

    public function test_it_uses_meta_microdata_and_visible_price_fallbacks(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response(
                <<<'HTML'
                <html><head>
                    <meta property="og:title" content="Oferta de prueba">
                    <meta property="og:image" content="/product.jpg">
                </head><body>
                    <span itemprop="description">Descripción del producto</span>
                    <span>Precio especial: $1,299.00 MXN</span>
                </body></html>
                HTML,
                200
            ),
        ]);

        $response = $this->postJson('/api/scrape', ['url' => 'https://shop.example/item']);

        $response->assertOk()->assertJson([
            'title' => 'Oferta de prueba',
            'description' => 'Descripción del producto',
            'image' => 'https://shop.example/product.jpg',
            'price' => '1299.00',
            'currency' => 'MXN',
        ]);
    }

    public function test_it_rejects_private_addresses_and_redirects_to_them(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/admin',
            ]),
        ]);

        $this->postJson('/api/scrape', ['url' => 'http://127.0.0.1/admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->postJson('/api/scrape', ['url' => 'https://shop.example/redirect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        Http::assertSentCount(1);
    }

    public function test_it_discards_generic_or_blocked_product_titles(): void
    {
        $this->mockPublicHost('amazon.com.mx');
        Http::fake([
            'https://amazon.com.mx/*' => Http::response(
                <<<'HTML'
                <html><head>
                    <meta property="og:title" content="aMaZoN.cOm.Mx">
                    <meta property="og:description" content="Robot Check - verify you are human">
                </head><body></body></html>
                HTML,
                200
            ),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://amazon.com.mx/dp/example'])
            ->assertOk()
            ->assertJsonPath('title', null)
            ->assertJsonPath('description', null);
    }

    public function test_it_discards_block_page_phrases_from_titles_and_descriptions(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response(
                <<<'HTML'
                <html><head>
                    <meta property="og:title" content="Just a moment...">
                    <meta property="og:description" content="Página no encontrada">
                </head><body></body></html>
                HTML,
                200
            ),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://shop.example/product'])
            ->assertOk()
            ->assertJsonPath('title', null)
            ->assertJsonPath('description', null);
    }

    public function test_it_discards_access_denied_and_captcha_metadata(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response(
                <<<'HTML'
                <html><head>
                    <meta property="og:title" content="Access Denied">
                    <meta property="og:description" content="Captcha required">
                </head><body></body></html>
                HTML,
                200
            ),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://shop.example/blocked'])
            ->assertOk()
            ->assertJsonPath('title', null)
            ->assertJsonPath('description', null);
    }

    public function test_it_removes_fragments_and_tracking_query_parameters_before_fetching(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/item*' => Http::response(
                '<html><head><title>Producto útil</title><meta property="product:price:amount" content="25.00"></head><body>Descripción</body></html>',
                200
            ),
        ]);

        $this->postJson('/api/scrape', [
            'url' => 'https://shop.example/item?utm_source=newsletter&fbclid=track&color=blue&reco_item=123#reviews',
        ])->assertOk()->assertJsonPath('blocked', false);

        Http::assertSent(fn ($request) => $request->url() === 'https://shop.example/item?color=blue');
    }

    public function test_it_discards_store_title_with_spaces_and_domain_suffix(): void
    {
        $this->mockPublicHost('mercadolibre.com.mx');
        Http::fake([
            'https://mercadolibre.com.mx/*' => Http::response(
                '<html><head><meta property="og:title" content="Mercado Libre"></head><body></body></html>',
                200
            ),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://mercadolibre.com.mx/item'])
            ->assertOk()
            ->assertJsonPath('title', null)
            ->assertJsonPath('store', 'mercadolibre.com.mx');
    }

    public function test_it_marks_short_captcha_pages_as_blocked_even_with_http_200(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response(
                '<html><body>Captcha verification</body></html>',
                200
            ),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://shop.example/product'])
            ->assertOk()
            ->assertExactJson([
                'title' => null,
                'description' => null,
                'image' => null,
                'price' => null,
                'currency' => null,
                'store' => 'shop.example',
                'blocked' => true,
                'provider' => null,
            ]);
    }

    public function test_it_marks_short_html_without_product_metadata_as_blocked(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response('<html><body>Loading</body></html>', 200),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://shop.example/loading'])
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('title', null)
            ->assertJsonPath('price', null);
    }

    public function test_it_returns_blocked_for_rate_limited_http_responses(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response('<html>Try again</html>', 429),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://shop.example/product'])
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('store', 'shop.example');
    }

    public function test_it_detects_the_store_error_block_message(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response(
                '<html><body>Hubo un error accediendo a esta pagina</body></html>',
                200
            ),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://shop.example/error-message'])
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('title', null);
    }

    public function test_scrape_debug_command_reports_provider_time_size_and_found_fields(): void
    {
        $this->mockPublicHost('debug.example');
        config()->set('scraper.chain', ['http']);
        Http::fake([
            'https://debug.example/*' => Http::response(
                '<html><head><meta property="og:title" content="Debug product"><meta property="product:price:amount" content="35.50"></head></html>',
                200
            ),
        ]);

        Artisan::call('scrape:debug', ['url' => 'https://debug.example/product']);
        $output = Artisan::output();

        $this->assertStringContainsString('Proveedor resuelto: http', $output);
        $this->assertStringContainsString('Tiempo:', $output);
        $this->assertStringContainsString('Tamaño HTML:', $output);
        $this->assertStringContainsString('Precio: 35.50', $output);
        $this->assertStringContainsString('Moneda:', $output);
        $this->assertStringContainsString('Imagen:', $output);
        $this->assertStringContainsString('Descripción:', $output);
        $this->assertStringContainsString('Mejores candidatos de precio', $output);
        $this->assertStringContainsString('title', $output);
        $this->assertStringContainsString('price', $output);
    }

    public function test_it_extracts_product_fields_from_json_ld_graph_and_aggregate_offer(): void
    {
        $this->mockPublicHost('shop.example');
        Http::fake([
            'https://shop.example/*' => Http::response(
                <<<'HTML'
                <html><head><script type="application/ld+json">
                {"@graph":[{"@type":["Product"],"name":"Cafetera","description":"Cafetera de acero","image":[{"url":"/coffee.jpg"}],"offers":[{"@type":"AggregateOffer","lowPrice":"$1,299.00","priceCurrency":"MXN"}]}]}
                </script></head><body></body></html>
                HTML,
                200
            ),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://shop.example/coffee'])
            ->assertOk()
            ->assertJsonPath('title', 'Cafetera')
            ->assertJsonPath('description', 'Cafetera de acero')
            ->assertJsonPath('image', 'https://shop.example/coffee.jpg')
            ->assertJsonPath('price', '1299.00')
            ->assertJsonPath('currency', 'MXN')
            ->assertJsonPath('blocked', false);
    }

    public function test_provider_chain_uses_fakes_in_order_and_stops_when_title_and_price_are_found(): void
    {
        $this->mockPublicHost('providers.example');
        config()->set('scraper.chain', ['http', 'browser', 'api']);
        $calls = [];

        $this->app->instance(HttpProvider::class, $this->fakeProvider(
            'http',
            new ScraperPage(
                '<script type="application/ld+json">{"@type":"Product","name":"Cámara"}</script>',
                'https://providers.example/camera',
                200
            ),
            $calls
        ));
        $this->app->instance(BrowserProvider::class, $this->fakeProvider(
            'browser',
            new ScraperPage(
                '<script type="application/ld+json">{"@type":"Product","name":"Cámara","offers":{"price":"$2,499","priceCurrency":"MXN"}}</script>',
                'https://providers.example/camera',
                200
            ),
            $calls
        ));

        $this->postJson('/api/scrape', ['url' => 'https://providers.example/camera-chain-test'])
            ->assertOk()
            ->assertJsonPath('title', 'Cámara')
            ->assertJsonPath('price', '2499.00')
            ->assertJsonPath('provider', 'browser');

        $this->assertSame(['http', 'browser'], $calls);
    }

    public function test_blocked_http_provider_does_not_prevent_browser_from_resolving_the_product(): void
    {
        $this->mockPublicHost('fallback.example');
        config()->set('scraper.chain', ['http', 'browser']);
        $calls = [];
        Http::fake([
            'https://fallback.example/*' => Http::response(
                '<html><body>Captcha verification</body></html>',
                200
            ),
        ]);
        $this->app->instance(BrowserProvider::class, $this->fakeProvider(
            'browser',
            new ScraperPage(
                '<script type="application/ld+json">{"@type":"Product","name":"Liverpool TV","offers":{"price":"8999","priceCurrency":"MXN"}}</script>',
                'https://fallback.example/tv',
                200
            ),
            $calls
        ));

        $response = $this->postJson('/api/scrape', ['url' => 'https://fallback.example/tv-fallback']);

        $response->assertOk()
            ->assertJsonPath('title', 'Liverpool TV')
            ->assertJsonPath('price', '8999.00')
            ->assertJsonPath('blocked', false)
            ->assertJsonPath('provider', 'browser');
        $this->assertSame(['browser'], $calls);
    }

    public function test_debug_command_reports_block_signal_and_continues_to_browser(): void
    {
        $this->mockPublicHost('debug-fallback.example');
        config()->set('scraper.chain', ['http', 'browser']);
        $calls = [];
        Http::fake([
            'https://debug-fallback.example/*' => Http::response(
                '<html><body>Captcha verification</body></html>',
                200
            ),
        ]);
        $this->app->instance(BrowserProvider::class, $this->fakeProvider(
            'browser',
            new ScraperPage(
                '<script type="application/ld+json">{"@type":"Product","name":"Audífonos","offers":{"price":"599","priceCurrency":"MXN"}}</script>',
                'https://debug-fallback.example/headphones',
                200
            ),
            $calls
        ));

        Artisan::call('scrape:debug', ['url' => 'https://debug-fallback.example/headphones']);
        $output = Artisan::output();

        $this->assertStringContainsString('http:', $output);
        $this->assertStringContainsString('señal: captcha', $output);
        $this->assertStringContainsString('browser:', $output);
        $this->assertStringContainsString('Proveedor resuelto: browser', $output);
        $this->assertSame(['browser'], $calls);
    }

    public function test_debug_command_can_run_only_the_requested_provider(): void
    {
        $this->mockPublicHost('browser-only.example');
        config()->set('scraper.chain', ['http', 'browser']);
        $calls = [];
        Http::fake();
        $this->app->instance(BrowserProvider::class, $this->fakeProvider(
            'browser',
            new ScraperPage(
                '<script type="application/ld+json">{"@type":"Product","name":"Solo navegador","offers":{"price":"45","priceCurrency":"MXN"}}</script>',
                'https://browser-only.example/product',
                200
            ),
            $calls
        ));

        Artisan::call('scrape:debug', [
            'url' => 'https://browser-only.example/product',
            '--provider' => 'browser',
        ]);

        $this->assertSame(['browser'], $calls);
        Http::assertNothingSent();
        $this->assertStringContainsString('Proveedor resuelto: browser', Artisan::output());
    }

    public function test_debug_command_saves_browser_html_and_screenshot(): void
    {
        $this->mockPublicHost('save-debug.example');
        $provider = Mockery::mock(BrowserProvider::class, [
            $this->app->make(PublicUrlValidator::class),
            $this->app->make(ScrapeUrlNormalizer::class),
        ])->makePartial();
        $provider->shouldReceive('name')->andReturn('browser');
        $provider->shouldReceive('fetchWithCapture')->once()->andReturn(new ScraperPage(
            '<script type="application/ld+json">{"@type":"Product","name":"Guardado","offers":{"price":"20","priceCurrency":"MXN"}}</script>',
            'https://save-debug.example/product',
            200,
            'fake-png-bytes',
        ));
        $this->app->instance(BrowserProvider::class, $provider);

        Artisan::call('scrape:debug', [
            'url' => 'https://save-debug.example/product',
            '--provider' => 'browser',
            '--save' => true,
        ]);
        $output = Artisan::output();
        preg_match('/HTML guardado: ([^\r\n]+)/', $output, $htmlMatch);
        preg_match('/Captura guardada: ([^\r\n]+)/', $output, $screenshotMatch);

        $this->assertNotEmpty($htmlMatch[1] ?? null);
        $this->assertNotEmpty($screenshotMatch[1] ?? null);
        $this->assertFileExists($htmlMatch[1]);
        $this->assertFileExists($screenshotMatch[1]);
        @unlink($htmlMatch[1]);
        @unlink($screenshotMatch[1]);
    }

    public function test_scrape_results_are_cached_by_normalized_url_for_repeated_tracking_links(): void
    {
        $this->mockPublicHost('cache.example');
        config()->set('scraper.chain', ['http']);
        $calls = [];
        $this->app->instance(HttpProvider::class, $this->fakeProvider(
            'http',
            new ScraperPage(
                '<script type="application/ld+json">{"@type":"Product","name":"Cámara digital","offers":{"price":"19.00","priceCurrency":"MXN"}}</script>',
                'https://cache.example/product',
                200
            ),
            $calls
        ));

        $this->postJson('/api/scrape', ['url' => 'https://cache.example/product?utm_source=one'])
            ->assertOk()
            ->assertJsonPath('provider', 'http');
        $this->postJson('/api/scrape', ['url' => 'https://cache.example/product?utm_source=two#details'])
            ->assertOk()
            ->assertJsonPath('provider', 'http');

        $this->assertSame(['http'], $calls);
    }

    public function test_domain_selectors_fill_fields_missing_from_generic_extraction(): void
    {
        $this->mockPublicHost('www.amazon.com.mx');
        Http::fake([
            'https://www.amazon.com.mx/*' => Http::response(
                <<<'HTML'
                <html><body><main>
                    <span class="a-price"><span class="a-offscreen">1,599</span></span>
                    <h1 id="productTitle">Bocina inteligente</h1>
                </main><img id="landingImage" src="/speaker.jpg"></body></html>
                HTML,
                200
            ),
        ]);

        $this->postJson('/api/scrape', ['url' => 'https://www.amazon.com.mx/dp/selector-test'])
            ->assertOk()
            ->assertJsonPath('title', 'Bocina inteligente')
            ->assertJsonPath('price', '1599.00')
            ->assertJsonPath('image', 'https://www.amazon.com.mx/speaker.jpg')
            ->assertJsonPath('provider', 'http');
    }

    public function test_deal_temperature_is_the_sum_of_its_votes(): void
    {
        $user = User::factory()->create();
        $deal = Deal::create([
            'user_id' => $user->id,
            'title' => 'Oferta',
            'url' => 'https://shop.example/item',
        ]);

        Vote::create(['user_id' => $user->id, 'deal_id' => $deal->id, 'value' => 1]);
        $secondUser = User::factory()->create();
        Vote::create(['user_id' => $secondUser->id, 'deal_id' => $deal->id, 'value' => -1]);

        $this->assertSame(0, $deal->fresh()->temperature);
    }

    private function mockPublicHost(string $host): void
    {
        $validator = Mockery::mock(PublicUrlValidator::class)->makePartial();
        $validator->shouldReceive('resolveHost')
            ->with($host)
            ->andReturn(['93.184.216.34']);

        $this->app->instance(PublicUrlValidator::class, $validator);
    }

    private function fakeProvider(string $name, ?ScraperPage $page, array &$calls): ScraperProvider
    {
        return new class($name, $page, $calls) implements ScraperProvider
        {
            private array $calls;

            public function __construct(
                private readonly string $providerName,
                private readonly ?ScraperPage $page,
                array &$calls
            ) {
                $this->calls = &$calls;
            }

            public function name(): string
            {
                return $this->providerName;
            }

            public function fetch(string $url): ?ScraperPage
            {
                $this->calls[] = $this->providerName;

                return $this->page;
            }
        };
    }
}
