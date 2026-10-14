<?php

namespace Tests\Feature;

use App\Models\Robot;
use App\Models\User;
use App\Services\ProductPageScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RobotPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_preview_scrapes_and_returns_sanitized_rendered_snapshot(): void
    {
        $user = User::factory()->create();
        $this->mock(ProductPageScraper::class)
            ->shouldReceive('scrapeDetailed')
            ->once()
            ->with('https://shop.example/item', null, true)
            ->andReturn($this->scrapeResult());

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots/preview', ['url' => 'https://shop.example/item'])
            ->assertOk()
            ->assertJsonPath('fields.title', 'Silla')
            ->assertJsonPath('fields.price', '1200.00')
            ->assertJsonPath('provider', 'browser')
            ->assertJsonPath('blocked', false)
            ->assertJsonPath('missing', [])
            ->assertJsonPath('stages.price', 'json_ld')
            ->assertJsonPath('stage_details.price.stage', 'json_ld')
            ->assertJsonPath('stage_details.price.raw', '1200.00');

        $this->assertStringContainsString(
            '<base href="https://shop.example/item">',
            $response->json('snapshot_html')
        );
        $this->assertSame(1, substr_count(strtolower($response->json('snapshot_html')), 'name="viewport"'));
        $this->assertStringContainsString('content="width=1280"', $response->json('snapshot_html'));
        $this->assertStringContainsString('overflow-x:hidden', $response->json('snapshot_html'));
    }

    public function test_preview_can_force_browser_provider(): void
    {
        $user = User::factory()->create();
        $this->mock(ProductPageScraper::class)
            ->shouldReceive('scrapeDetailed')
            ->once()
            ->with('https://shop.example/item', 'browser', true)
            ->andReturn($this->scrapeResult());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots/preview', [
                'url' => 'https://shop.example/item',
                'provider' => 'browser',
            ])
            ->assertOk()
            ->assertJsonPath('provider', 'browser');
    }

    public function test_preview_missing_contains_only_required_base_fields(): void
    {
        $user = User::factory()->create();
        $result = $this->scrapeResult();
        $result['store'] = null;
        $this->mock(ProductPageScraper::class)
            ->shouldReceive('scrapeDetailed')
            ->once()
            ->andReturn($result);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots/preview', ['url' => 'https://shop.example/item'])
            ->assertOk()
            ->assertJsonPath('missing', [])
            ->assertJsonPath('optional_missing', ['availability', 'seller', 'original_price']);
    }

    public function test_preview_applies_owned_store_profile_and_records_selector_results(): void
    {
        $user = User::factory()->create();
        $profile = $user->storeProfiles()->create([
            'domain' => 'shop.example',
            'selectors' => [
                'title' => ['css' => '.product-name', 'attr' => 'text'],
                'price' => ['css' => '.sale-price', 'attr' => 'text'],
                'image' => ['css' => '.missing-image', 'attr' => 'src'],
            ],
        ]);
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with('https://www.shop.example/item', null, true)
            ->andReturn($this->scrapeResult(
                '<html><body><h1 class="product-name">Perfil title</h1><span class="sale-price">$85.50</span></body></html>'
            ));
        $scraper->shouldReceive('parsePrice')
            ->once()
            ->with('$85.50')
            ->andReturn('85.50');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots/preview', ['url' => 'https://www.shop.example/item'])
            ->assertOk()
            ->assertJsonPath('fields.title', 'Perfil title')
            ->assertJsonPath('fields.price', '85.50')
            ->assertJsonPath('field_sources.title', 'store_profile')
            ->assertJsonPath('field_sources.price', 'store_profile')
            ->assertJsonPath('has_store_profile', true)
            ->assertJsonPath('store_profile_active', true);

        $this->assertSame(2, $profile->fresh()->success_count);
        $this->assertSame(1, $profile->fresh()->fail_count);
    }

    public function test_user_can_forget_only_their_store_profile(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerProfile = $owner->storeProfiles()->create([
            'domain' => 'shop.example',
            'selectors' => ['title' => ['css' => 'h1', 'attr' => 'text']],
        ]);
        $otherProfile = $other->storeProfiles()->create([
            'domain' => 'shop.example',
            'selectors' => ['title' => ['css' => 'h1', 'attr' => 'text']],
        ]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/store-profiles', ['url' => 'https://www.shop.example/product'])
            ->assertNoContent();

        $this->assertDatabaseMissing('store_profiles', ['id' => $ownerProfile->id]);
        $this->assertDatabaseHas('store_profiles', ['id' => $otherProfile->id]);
    }

    public function test_profile_is_disabled_after_repeated_selector_failures_and_preview_warns(): void
    {
        $user = User::factory()->create();
        $profile = $user->storeProfiles()->create([
            'domain' => 'shop.example',
            'selectors' => [
                'title' => ['css' => '.missing-title', 'attr' => 'text'],
                'description' => ['css' => '.missing-description', 'attr' => 'text'],
                'image' => ['css' => '.missing-image', 'attr' => 'src'],
                'price' => ['css' => '.missing-price', 'attr' => 'text'],
                'currency' => ['css' => '.missing-currency', 'attr' => 'content'],
                'store' => ['css' => '.missing-store', 'attr' => 'text'],
            ],
        ]);
        $this->mock(ProductPageScraper::class)
            ->shouldReceive('scrapeDetailed')
            ->once()
            ->with('https://shop.example/item', null, true)
            ->andReturn($this->scrapeResult('<html><body>Producto</body></html>'));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots/preview', ['url' => 'https://shop.example/item'])
            ->assertOk()
            ->assertJsonPath('store_profile_active', false)
            ->assertJsonPath('profile_notice', 'El perfil de selectores de esta tienda se desactivó por fallos repetidos.');

        $this->assertSame(6, $profile->fresh()->fail_count);
        $this->assertFalse($profile->fresh()->is_active);
    }

    public function test_creating_robot_with_preview_data_saves_snapshot_and_records_reading_without_dispatch(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots', [
                'url' => 'https://shop.example/item',
                'name' => 'Silla',
                'interval_minutes' => 10,
                'selectors' => [
                    'price' => ['css' => '.price', 'attr' => 'text'],
                    'currency' => ['css' => '.currency', 'attr' => 'content'],
                    'store' => ['css' => '.store', 'attr' => 'text'],
                ],
                'overrides' => [
                    'title' => 'Silla',
                    'description' => 'Silla de oficina',
                    'image' => 'https://shop.example/silla.jpg',
                    'price' => '1200.00',
                    'currency' => 'MXN',
                    'store' => 'Shop',
                    'preview_provider' => 'http',
                ],
                'snapshot_html' => '<html><body><img src="/silla.jpg"></body></html>',
            ])
            ->assertCreated()
            ->assertJsonPath('data.selectors.price.css', '.price')
            ->assertJsonPath('data.selectors.currency.css', '.currency')
            ->assertJsonPath('data.selectors.store.css', '.store')
            ->assertJsonPath('data.overrides.preview_provider', 'http')
            ->assertJsonPath('data.snapshot_path', 'robots/1.html');

        $robot = Robot::firstOrFail();
        $this->assertSame('1200.00', $robot->last_price);
        $this->assertDatabaseHas('robot_runs', [
            'robot_id' => $robot->id,
            'status' => 'success',
            'provider' => 'preview',
            'price' => '1200.00',
        ]);
        Storage::disk('local')->assertExists('robots/'.$robot->id.'.html');
        Queue::assertNothingPushed();

        $this->actingAs($user, 'sanctum')
            ->get('/api/robots/'.$robot->id.'/snapshot')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('<base href="https://shop.example/item">', false)
            ->assertSee('src="/silla.jpg"', false);
    }

    public function test_creating_robot_accepts_a_500_kilobyte_snapshot(): void
    {
        $user = User::factory()->create();
        $snapshot = '<html><body><div>'.str_repeat('x', 500 * 1024).'</div></body></html>';

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots', [
                'url' => 'https://shop.example/item',
                'overrides' => [
                    'title' => 'Producto',
                    'price' => '1200.00',
                ],
                'snapshot_html' => $snapshot,
            ])
            ->assertCreated();

        Storage::disk('local')->assertExists('robots/1.html');
    }

    public function test_oversized_snapshot_is_reduced_before_validation_and_rejected_if_still_over_limit(): void
    {
        $user = User::factory()->create();
        $reducible = '<html><head><style>'.str_repeat('x', 3 * 1024 * 1024).'</style></head><body>Item</body></html>';

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots', [
                'url' => 'https://shop.example/reducible',
                'overrides' => ['title' => 'Producto', 'price' => '10.00'],
                'snapshot_html' => $reducible,
            ])
            ->assertCreated();

        $tooLarge = '<html><body>'.str_repeat('x', 3 * 1024 * 1024 + 1).'</body></html>';
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots', [
                'url' => 'https://shop.example/too-large',
                'overrides' => ['title' => 'Producto', 'price' => '10.00'],
                'snapshot_html' => $tooLarge,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('snapshot_html')
            ->assertJsonPath('errors.snapshot_html.0', 'El snapshot supera 3 MB');
    }

    public function test_snapshot_sanitizer_removes_active_content_and_dangerous_attributes(): void
    {
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/item',
            'interval_minutes' => 10,
        ]);
        $this->mock(ProductPageScraper::class)
            ->shouldReceive('scrapeDetailed')
            ->once()
            ->andReturn($this->scrapeResult(
                '<html><head><link rel="preload" href="/x.css"></head><body><script>alert(1)</script><iframe src="https://evil.example"></iframe><object></object><embed><noscript>hidden</noscript><a onclick="x()" href="java&#x09;script:alert(1)">Item</a><img src="javascript:alert(2)"></body></html>'
            ));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots/'.$robot->id.'/preview')
            ->assertOk();
        $snapshot = $response->json('snapshot_html');

        $this->assertStringNotContainsString('<script', $snapshot);
        $this->assertStringNotContainsString('<iframe', $snapshot);
        $this->assertStringNotContainsString('<object', $snapshot);
        $this->assertStringNotContainsString('<embed', $snapshot);
        $this->assertStringNotContainsString('<noscript', $snapshot);
        $this->assertStringNotContainsString('onclick=', $snapshot);
        $this->assertStringNotContainsString('javascript:', $snapshot);
        $this->assertStringNotContainsString('rel="preload"', $snapshot);
        $this->assertSame('robots/'.$robot->id.'.html', $robot->fresh()->snapshot_path);
    }

    public function test_snapshot_and_preview_are_private_and_input_shapes_are_validated(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $robot = $owner->robots()->create([
            'url' => 'https://shop.example/item',
            'interval_minutes' => 10,
        ]);

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/robots/'.$robot->id.'/preview')
            ->assertForbidden();
        $this->actingAs($other, 'sanctum')
            ->get('/api/robots/'.$robot->id.'/snapshot')
            ->assertForbidden();
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/robots', [
                'url' => 'https://shop.example/item',
                'selectors' => ['price' => ['css' => '.price', 'attr' => 'href']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selectors.price.attr');
    }

    private function scrapeResult(?string $html = null): array
    {
        return [
            'title' => 'Silla',
            'description' => 'Silla de oficina',
            'image' => 'https://shop.example/silla.jpg',
            'price' => '1200.00',
            'currency' => 'MXN',
            'store' => 'shop.example',
            'blocked' => false,
            'provider' => 'browser',
            '_diagnostics' => [
                'provider' => 'browser',
                'field_stages' => ['title' => 'json_ld', 'price' => 'json_ld'],
                'field_sources' => [
                    'title' => ['stage' => 'json_ld', 'selector' => 'JSON-LD Product.name', 'raw' => 'Silla'],
                    'price' => ['stage' => 'json_ld', 'selector' => 'JSON-LD Product.offers.price', 'raw' => '1200.00'],
                ],
                'html' => $html ?? '<html><head><script>alert(1)</script></head><body><h1>Silla</h1></body></html>',
            ],
        ];
    }
}
