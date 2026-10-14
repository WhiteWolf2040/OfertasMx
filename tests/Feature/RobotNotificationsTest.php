<?php

namespace Tests\Feature;

use App\Jobs\RunRobotJob;
use App\Models\RobotNotification;
use App\Models\RobotRun;
use App\Models\User;
use App\Services\ProductPageScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_price_run_creates_target_and_drop_notifications_once_per_price(): void
    {
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/product',
            'interval_minutes' => 10,
            'target_price' => '100.00',
            'notify_on_drop' => true,
        ]);
        RobotRun::create([
            'robot_id' => $robot->id,
            'status' => 'success',
            'price' => '120.00',
            'created_at' => now()->subMinutes(10),
        ]);

        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->twice()
            ->with($robot->url, null, false)
            ->andReturn($this->successfulScrape('99.00'));

        (new RunRobotJob($robot->id))->handle($scraper);
        (new RunRobotJob($robot->id))->handle($scraper);

        $this->assertDatabaseCount('notifications_robot', 2);
        $this->assertDatabaseHas('notifications_robot', [
            'robot_id' => $robot->id,
            'type' => 'target_reached',
            'price' => '99.00',
            'previous_price' => '120.00',
        ]);
        $this->assertDatabaseHas('notifications_robot', [
            'robot_id' => $robot->id,
            'type' => 'price_drop',
            'price' => '99.00',
            'previous_price' => '120.00',
        ]);
        $this->assertNotNull($robot->fresh()->alerted_at);
    }

    public function test_notifications_are_private_prioritize_unread_and_can_be_marked_read(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $robot = $owner->robots()->create([
            'url' => 'https://shop.example/product',
            'interval_minutes' => 10,
        ]);
        $unread = RobotNotification::create([
            'robot_id' => $robot->id,
            'user_id' => $owner->id,
            'type' => 'price_drop',
            'price' => '90.00',
            'previous_price' => '100.00',
            'created_at' => now(),
        ]);
        $read = RobotNotification::create([
            'robot_id' => $robot->id,
            'user_id' => $owner->id,
            'type' => 'target_reached',
            'price' => '80.00',
            'read_at' => now(),
            'created_at' => now()->addMinute(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $unread->id)
            ->assertJsonPath('unread_count', 1);
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/notifications/'.$unread->id.'/read')
            ->assertNotFound();
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/notifications/'.$unread->id.'/read')
            ->assertOk();
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/notifications/read-all')
            ->assertOk();
        $this->assertNotNull($read->fresh()->read_at);
        $this->assertSame(0, $owner->robotNotifications()->whereNull('read_at')->count());
    }

    public function test_robot_target_alert_settings_are_validated_and_returned(): void
    {
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/product',
            'interval_minutes' => 10,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/robots/'.$robot->id, [
                'target_price' => '75.50',
                'notify_on_drop' => true,
                'notify_on_restock' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.target_price', '75.50')
            ->assertJsonPath('data.notify_on_drop', true)
            ->assertJsonPath('data.notify_on_restock', true);
        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/robots/'.$robot->id, ['target_price' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_price');
    }

    public function test_restock_notification_is_created_only_when_availability_changes_from_out_to_available(): void
    {
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/product',
            'interval_minutes' => 10,
            'notify_on_restock' => true,
        ]);
        RobotRun::create([
            'robot_id' => $robot->id,
            'status' => 'success',
            'price' => '99.00',
            'data' => ['availability' => 'Agotado'],
            'created_at' => now()->subMinutes(10),
        ]);
        $scraper = $this->mock(ProductPageScraper::class);
        $scraper->shouldReceive('scrapeDetailed')
            ->twice()
            ->with($robot->url, null, false)
            ->andReturn($this->successfulScrape('99.00') + ['availability' => 'Disponible']);

        (new RunRobotJob($robot->id))->handle($scraper);
        (new RunRobotJob($robot->id))->handle($scraper);

        $this->assertDatabaseCount('notifications_robot', 1);
        $this->assertDatabaseHas('notifications_robot', [
            'robot_id' => $robot->id,
            'type' => 'back_in_stock',
            'price' => '99.00',
        ]);
    }

    private function successfulScrape(string $price): array
    {
        return [
            'title' => 'Producto',
            'description' => null,
            'image' => null,
            'price' => $price,
            'currency' => 'MXN',
            'store' => 'shop.example',
            'blocked' => false,
            'provider' => 'http',
            '_diagnostics' => [],
        ];
    }
}
