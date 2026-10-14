<?php

namespace Tests\Feature;

use App\Jobs\RunRobotJob;
use App\Models\RobotRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RobotsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_robots(): void
    {
        $this->getJson('/api/robots')->assertUnauthorized();
        $this->postJson('/api/robots', ['url' => 'https://shop.example/item'])->assertUnauthorized();
    }

    public function test_user_can_create_list_and_read_owned_robots(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/item',
            'interval_minutes' => 10,
            'last_price' => '99.50',
            'last_status' => 'success',
        ]);
        RobotRun::create([
            'robot_id' => $robot->id,
            'status' => 'success',
            'provider' => 'http',
            'price' => '99.50',
            'created_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/robots')
            ->assertOk()
            ->assertJsonPath('data.0.id', $robot->id)
            ->assertJsonPath('data.0.last_price', '99.50')
            ->assertJsonPath('data.0.last_status', 'success');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/robots/'.$robot->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.runs')
            ->assertJsonPath('data.runs.0.provider', 'http');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/robots/'.$robot->id.'/prices')
            ->assertOk()
            ->assertJsonPath('0.price', '99.50')
            ->assertJsonStructure([['date', 'price']]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots', ['url' => 'https://another.example/item'])
            ->assertCreated()
            ->assertJsonPath('data.interval_minutes', 10)
            ->assertJsonPath('data.name', 'another.example');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots', [
                'url' => 'https://store.example/item',
                'selectors' => [
                    'price' => ['css' => '.price', 'attr' => 'text'],
                ],
                'overrides' => ['title' => 'Producto'],
            ])
            ->assertCreated();
        $this->assertDatabaseHas('store_profiles', [
            'user_id' => $user->id,
            'domain' => 'store.example',
        ]);

        Queue::assertPushed(RunRobotJob::class, 1);
    }

    public function test_user_can_update_pause_manually_run_and_delete_robot(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/item',
            'interval_minutes' => 10,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/robots/'.$robot->id, [
                'name' => 'Mi producto',
                'interval_minutes' => 30,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mi producto')
            ->assertJsonPath('data.interval_minutes', 30)
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/robots/'.$robot->id.'/run')
            ->assertAccepted();

        Queue::assertPushed(RunRobotJob::class, 1);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/robots/'.$robot->id)
            ->assertOk();
        $this->assertDatabaseMissing('robots', ['id' => $robot->id]);
    }

    public function test_patch_saves_extra_selectors_and_remembers_the_profile(): void
    {
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/item',
            'interval_minutes' => 10,
        ]);
        $selectors = [
            'extras' => [[
                'key' => 'shipping',
                'label' => 'Envío',
                'css' => '.shipping',
                'attr' => 'text',
                'type' => 'custom',
                'notify' => true,
            ]],
        ];

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/robots/'.$robot->id, [
                'selectors' => $selectors,
                'overrides' => ['title' => 'Producto actualizado'],
                'target_price' => '89.00',
                'notify_on_drop' => true,
                'notify_on_restock' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.selectors.extras.0.key', 'shipping')
            ->assertJsonPath('data.overrides.title', 'Producto actualizado')
            ->assertJsonPath('data.target_price', '89.00')
            ->assertJsonPath('data.notify_on_drop', true)
            ->assertJsonPath('data.notify_on_restock', true);

        $this->assertDatabaseHas('store_profiles', [
            'user_id' => $user->id,
            'domain' => 'shop.example',
        ]);
        $this->assertSame($selectors, $user->storeProfiles()->sole()->selectors);
    }

    public function test_patch_rejects_duplicate_extra_keys_and_css_over_500_characters(): void
    {
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/item',
            'interval_minutes' => 10,
        ]);
        $extra = [
            'key' => 'shipping',
            'label' => 'Envío',
            'css' => '.shipping',
            'attr' => 'text',
            'type' => 'custom',
            'notify' => false,
        ];

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/robots/'.$robot->id, [
                'selectors' => ['extras' => [$extra, $extra]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'selectors.extras.0.key',
                'selectors.extras.1.key',
            ]);

        $extra['css'] = str_repeat('a', 501);
        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/robots/'.$robot->id, [
                'selectors' => ['extras' => [$extra]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selectors.extras.0.css');
    }

    public function test_robots_are_private_and_creation_validates_url_and_interval(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $robot = $owner->robots()->create([
            'url' => 'https://shop.example/item',
            'interval_minutes' => 10,
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/robots/'.$robot->id)
            ->assertForbidden();
        $this->actingAs($other, 'sanctum')
            ->patchJson('/api/robots/'.$robot->id, ['name' => 'Robado'])
            ->assertForbidden();
        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/robots/'.$robot->id)
            ->assertForbidden();
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/robots/'.$robot->id.'/run')
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/robots', ['url' => 'ftp://invalid.example/item'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/robots', [
                'url' => 'https://shop.example/item',
                'interval_minutes' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('interval_minutes');
    }

    public function test_robot_stats_returns_range_metrics_and_filters_repeated_prices(): void
    {
        $user = User::factory()->create();
        $robot = $user->robots()->create([
            'url' => 'https://shop.example/item',
            'interval_minutes' => 10,
        ]);
        $runPoints = [
            ['price' => '100.00', 'created_at' => now()->subDays(10)],
            ['price' => '100.00', 'created_at' => now()->subDays(10)->addHour()],
            ['price' => '90.00', 'created_at' => now()->subDays(2)],
            ['price' => '90.00', 'created_at' => now()->subDays(2)->addHours(7)],
            ['price' => '80.00', 'created_at' => now()->subHour()],
        ];
        foreach ($runPoints as $point) {
            RobotRun::create([
                'robot_id' => $robot->id,
                'status' => 'success',
                'price' => $point['price'],
                'created_at' => $point['created_at'],
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/robots/'.$robot->id.'/stats?range=all')
            ->assertOk()
            ->assertJsonPath('current', '80.00')
            ->assertJsonPath('min', '80.00')
            ->assertJsonPath('max', '100.00')
            ->assertJsonPath('avg', '90.00')
            ->assertJsonPath('first', '100.00')
            ->assertJsonPath('drop_pct_from_max', 20)
            ->assertJsonCount(4, 'points');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/robots/'.$robot->id.'/stats?range=24h')
            ->assertOk()
            ->assertJsonPath('current', '80.00')
            ->assertJsonPath('first', '80.00')
            ->assertJsonCount(1, 'points');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/robots/'.$robot->id.'/stats?range=3d')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('range');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/robots/'.$robot->id.'/stats')
            ->assertForbidden();
    }
}
