<?php

namespace Tests\Feature;

use App\Jobs\RunRobotJob;
use App\Models\Robot;
use App\Models\RobotRun;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\ProductPageScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RobotExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_robot_job_saves_scraped_data_and_reschedules_robot(): void
    {
        $robot = $this->createRobot();
        $startedAt = now()->startOfSecond();
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with($robot->url, null, false)
            ->andReturn([
                'title' => 'Producto detectado',
                'description' => 'Descripción',
                'image' => 'https://shop.example/image.jpg',
                'price' => '129.90',
                'currency' => 'MXN',
                'store' => 'shop.example',
                'blocked' => false,
                'provider' => 'browser',
                '_diagnostics' => ['elapsed_ms' => 100],
            ]);

        (new RunRobotJob($robot->id))->handle($scraper);

        $robot->refresh();
        $this->assertSame('Producto detectado', $robot->name);
        $this->assertSame('129.90', $robot->last_price);
        $this->assertSame('MXN', $robot->last_currency);
        $this->assertSame('success', $robot->last_status);
        $this->assertNotNull($robot->last_run_at);
        $this->assertTrue($robot->next_run_at->greaterThanOrEqualTo($startedAt->copy()->addMinutes(10)));

        $this->assertDatabaseHas('robot_runs', [
            'robot_id' => $robot->id,
            'status' => 'success',
            'provider' => 'browser',
            'price' => '129.90',
        ]);
    }

    public function test_dispatch_due_advances_schedule_and_queues_only_due_active_robots(): void
    {
        $due = $this->createRobot();
        $inactive = $this->createRobot(['is_active' => false]);
        $notDue = $this->createRobot(['next_run_at' => now()->addHour()]);
        Queue::fake();

        Artisan::call('robots:dispatch-due');

        Queue::assertPushedTimes(RunRobotJob::class, 1);
        Queue::assertPushed(
            RunRobotJob::class,
            fn (RunRobotJob $job): bool => $job->robotId === $due->id && $job->connection === 'database'
        );
        $this->assertTrue($due->fresh()->next_run_at->isFuture());
        $this->assertFalse($inactive->fresh()->next_run_at?->isFuture() ?? false);
        $this->assertTrue($notDue->fresh()->next_run_at->isFuture());
    }

    public function test_robot_job_records_scrape_exception_as_failed_and_reschedules(): void
    {
        $robot = $this->createRobot();
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with($robot->url, null, false)
            ->andThrow(new \RuntimeException('Scraper unavailable'));

        (new RunRobotJob($robot->id))->handle($scraper);

        $this->assertDatabaseHas('robot_runs', [
            'robot_id' => $robot->id,
            'status' => 'failed',
            'error' => 'Scraper unavailable',
        ]);
        $this->assertSame('failed', $robot->fresh()->last_status);
        $this->assertTrue($robot->fresh()->next_run_at->isFuture());
    }

    public function test_selector_miss_on_http_retries_browser_and_uses_selected_price(): void
    {
        $robot = $this->createRobot([
            'selectors' => [
                'title' => ['css' => 'h1', 'attr' => 'text'],
                'price' => ['css' => '.price', 'attr' => 'text'],
                'currency' => ['css' => '.currency', 'attr' => 'content'],
                'store' => ['css' => '.store', 'attr' => 'text'],
            ],
        ]);
        $this->assertIsArray($robot->selectors);
        $this->assertArrayHasKey('price', $robot->selectors);
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with($robot->url, null, true)
            ->andReturn($this->scrapeResult(
                '<html><body><h1>Producto</h1></body></html>',
                'http',
                '30.00',
                'Producto renderizado'
            ));
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with($robot->url, 'browser', true)
            ->andReturn($this->scrapeResult(
                '<html><body><h1>Producto renderizado</h1><div class="price">1.299,00</div><meta class="currency" content="MXN"><span class="store">Tienda</span></body></html>',
                'browser',
                null
            ));
        $scraper->shouldReceive('parsePrice')
            ->once()
            ->with('1.299,00')
            ->andReturn('1299.00');

        (new RunRobotJob($robot->id))->handle($scraper);

        $this->assertDatabaseHas('robot_runs', [
            'robot_id' => $robot->id,
            'status' => 'success',
            'provider' => 'browser',
            'price' => '1299.00',
        ]);
    }

    public function test_missing_selector_uses_generic_price_and_marks_run_partial_without_price_override(): void
    {
        $robot = $this->createRobot([
            'selectors' => [
                'price' => ['css' => '.gone-price', 'attr' => 'text'],
            ],
            'overrides' => [
                'title' => 'Título manual',
                'description' => 'Descripción manual',
                'image' => 'https://shop.example/manual.jpg',
                'price' => '999.00',
            ],
        ]);
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with($robot->url, null, true)
            ->andReturn($this->scrapeResult(
                '<html><body><h1>Producto</h1><div class="price">$120.00</div></body></html>',
                'browser',
                '120.00',
                null
            ));

        (new RunRobotJob($robot->id))->handle($scraper);

        $robot->refresh();
        $this->assertSame('Título manual', $robot->name);
        $this->assertSame('120.00', $robot->last_price);
        $this->assertDatabaseHas('robot_runs', [
            'robot_id' => $robot->id,
            'status' => 'partial',
            'price' => '120.00',
            'error' => 'selector price sin resultado',
        ]);
    }

    public function test_job_uses_and_updates_store_profile_when_robot_has_no_own_selectors(): void
    {
        $robot = $this->createRobot();
        $profile = StoreProfile::create([
            'user_id' => $robot->user_id,
            'domain' => 'shop.example',
            'selectors' => [
                'price' => ['css' => '.price', 'attr' => 'text'],
            ],
        ]);
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with($robot->url, null, true)
            ->andReturn($this->scrapeResult(
                '<html><body><span class="price">$45.00</span></body></html>',
                'http',
                '45.00'
            ));
        $scraper->shouldReceive('parsePrice')
            ->once()
            ->with('$45.00')
            ->andReturn('45.00');

        (new RunRobotJob($robot->id))->handle($scraper);

        $this->assertSame('45.00', $robot->fresh()->last_price);
        $this->assertSame(1, $profile->fresh()->success_count);
        $this->assertSame(0, $profile->fresh()->fail_count);
    }

    public function test_robot_selectors_capture_availability_seller_and_original_price(): void
    {
        $robot = $this->createRobot([
            'selectors' => [
                'availability' => ['css' => '.availability', 'attr' => 'text'],
                'seller' => ['css' => '.seller', 'attr' => 'text'],
                'original_price' => ['css' => '.list-price', 'attr' => 'text'],
            ],
        ]);
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with($robot->url, null, true)
            ->andReturn($this->scrapeResult(
                '<html><body><div class="availability">Disponible</div><span class="seller">Tienda oficial</span><s class="list-price">$150.00</s></body></html>',
                'browser',
                '99.00'
            ));
        $scraper->shouldReceive('parsePrice')
            ->once()
            ->with('$150.00')
            ->andReturn('150.00');

        (new RunRobotJob($robot->id))->handle($scraper);

        $run = RobotRun::query()->where('robot_id', $robot->id)->sole();
        $this->assertSame('Disponible', $run->data['availability']);
        $this->assertSame('Tienda oficial', $run->data['seller']);
        $this->assertSame('150.00', $run->data['original_price']);
    }

    public function test_robot_run_applies_saved_extra_selectors_from_patch(): void
    {
        $robot = $this->createRobot([
            'selectors' => [
                'extras' => [[
                    'key' => 'shipping',
                    'label' => 'Envío',
                    'css' => '.shipping',
                    'attr' => 'text',
                    'type' => 'custom',
                    'notify' => true,
                ]],
            ],
        ]);
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->once()
            ->with($robot->url, null, true)
            ->andReturn($this->scrapeResult(
                '<html><body><div class="shipping">Entrega gratis</div></body></html>',
                'http',
                '99.00',
                'Producto'
            ));

        (new RunRobotJob($robot->id))->handle($scraper);

        $run = RobotRun::query()->where('robot_id', $robot->id)->sole();
        $this->assertSame(['shipping' => 'Entrega gratis'], $run->data['extras']);
        $this->assertArrayNotHasKey('shipping', $run->data['extras_warnings'] ?? []);
    }

    private function scrapeResult(string $html, string $provider, ?string $price, ?string $title = 'Producto genérico'): array
    {
        return [
            'title' => $title,
            'description' => null,
            'image' => null,
            'price' => $price,
            'currency' => 'MXN',
            'store' => 'shop.example',
            'blocked' => false,
            'provider' => $provider,
            '_diagnostics' => ['html' => $html, 'field_stages' => []],
        ];
    }

    private function createRobot(array $attributes = []): Robot
    {
        $user = User::factory()->create();

        return $user->robots()->create(array_merge([
            'url' => 'https://shop.example/product',
            'interval_minutes' => 10,
        ], $attributes));
    }
}
