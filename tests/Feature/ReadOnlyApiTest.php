<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\RobotNotification;
use App\Models\RobotRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ReadOnlyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_pagination_and_detail_do_not_change_legacy_endpoints(): void
    {
        $first = Category::create(['name' => 'Hogar', 'slug' => 'hogar']);
        Category::create(['name' => 'Moda', 'slug' => 'moda']);

        $user = User::factory()->create();
        $firstPage = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/categories?limit=1&offset=0');
        $firstPage
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('previous', null)
            ->assertJsonPath('results.0.id', $first->id)
            ->assertJsonPath('results.0.name', 'Hogar');
        $this->assertStringContainsString('limit=1', $firstPage->json('next'));
        $this->assertStringContainsString('offset=1', $firstPage->json('next'));

        $secondPage = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/categories?limit=1&offset=1');
        $secondPage
            ->assertOk()
            ->assertJsonPath('results.0.name', 'Moda');
        $this->assertStringContainsString('offset=0', $secondPage->json('previous'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/categories/'.$first->id)
            ->assertOk()
            ->assertJsonPath('id', $first->id)
            ->assertJsonMissingPath('data');

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_deal_relations_are_urls_and_user_secrets_are_not_returned(): void
    {
        $category = Category::create(['name' => 'Tecnología', 'slug' => 'tecnologia']);
        $user = User::factory()->create();
        $deal = $user->deals()->create([
            'title' => 'Laptop',
            'url' => 'https://shop.example/laptop',
            'price' => '99.90',
            'currency' => 'MXN',
            'store' => 'Tienda',
            'category_id' => $category->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/deals/'.$deal->id)
            ->assertOk()
            ->assertJsonPath('user', config('app.url').'/api/v1/users/'.$user->id)
            ->assertJsonPath('category', config('app.url').'/api/v1/categories/'.$category->id)
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('email');
    }

    public function test_social_resources_use_relation_urls_and_user_profiles_are_safe(): void
    {
        $category = Category::create(['name' => 'Hogar', 'slug' => 'hogar']);
        $user = User::factory()->create();
        $deal = $user->deals()->create([
            'title' => 'Silla',
            'url' => 'https://shop.example/silla',
            'price' => '25.00',
            'store' => 'Tienda',
            'category_id' => $category->id,
        ]);
        $comment = $user->comments()->create([
            'deal_id' => $deal->id,
            'body' => 'Buen precio',
        ]);
        $vote = $user->votes()->create([
            'deal_id' => $deal->id,
            'value' => 1,
        ]);

        $this->getJson('/api/v1/users')->assertUnauthorized();

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/comments/'.$comment->id)
            ->assertOk()
            ->assertJsonPath('user', config('app.url').'/api/v1/users/'.$user->id)
            ->assertJsonPath('deal', config('app.url').'/api/v1/deals/'.$deal->id);

        $this->withToken($token)
            ->getJson('/api/v1/votes/'.$vote->id)
            ->assertOk()
            ->assertJsonPath('user', config('app.url').'/api/v1/users/'.$user->id)
            ->assertJsonPath('deal', config('app.url').'/api/v1/deals/'.$deal->id);

        $this->withToken($token)
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.name', $user->name)
            ->assertJsonMissingPath('results.0.email')
            ->assertJsonMissingPath('results.0.password')
            ->assertJsonMissingPath('results.0.remember_token');
    }

    public function test_private_v1_resources_require_auth_and_only_return_owned_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownedRobot = $owner->robots()->create([
            'url' => 'https://shop.example/owned',
            'interval_minutes' => 10,
        ]);
        $otherRobot = $other->robots()->create([
            'url' => 'https://shop.example/private',
            'interval_minutes' => 10,
        ]);
        RobotRun::create([
            'robot_id' => $ownedRobot->id,
            'status' => 'success',
            'created_at' => now(),
        ]);
        RobotRun::create([
            'robot_id' => $otherRobot->id,
            'status' => 'success',
            'created_at' => now(),
        ]);
        $owner->storeProfiles()->create([
            'domain' => 'shop.example',
            'selectors' => ['price' => ['css' => '.price']],
        ]);
        RobotNotification::create([
            'robot_id' => $ownedRobot->id,
            'user_id' => $owner->id,
            'type' => 'price_drop',
            'price' => '50.00',
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/robots')->assertUnauthorized();
        $this->getJson('/api/v1/robot-runs')->assertUnauthorized();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/robots')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.id', $ownedRobot->id);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/robots/'.$otherRobot->id)
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/robot-runs')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.robot', config('app.url').'/api/v1/robots/'.$ownedRobot->id);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/store-profiles')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/robot-notifications')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.robot', config('app.url').'/api/v1/robots/'.$ownedRobot->id)
            ->assertJsonPath('results.0.user', config('app.url').'/api/v1/users/'.$owner->id);
    }

    public function test_invalid_pagination_and_missing_records_return_json_errors(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/categories?limit=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/categories/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Registro no encontrado.');
    }

    public function test_versioned_get_resources_require_authentication_and_return_json_errors(): void
    {
        foreach ([
            'categories',
            'deals',
            'comments',
            'votes',
            'robots',
            'robot-runs',
            'store-profiles',
            'robot-notifications',
            'users',
        ] as $resource) {
            $response = $this->get('/api/v1/'.$resource);
            $response->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
            $this->assertStringContainsString(
                'application/json',
                (string) $response->headers->get('content-type')
            );

            $this->get('/api/v1/'.$resource.'/1')
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Unauthenticated.');
        }

        $this->withToken('invalid-token')
            ->get('/api/v1/categories')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_api_login_returns_a_sanctum_token_and_logout_revokes_it(): void
    {
        $user = User::factory()->create([
            'email' => 'api-user@example.com',
            'password' => 'secret-password',
        ]);

        $login = $this->post('/api/v1/login', [
            'email' => 'api-user@example.com',
            'password' => 'secret-password',
        ]);
        $login->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $token = $login->json('token');
        $this->withToken($token)
            ->getJson('/api/v1/categories')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/logout')
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/categories')
            ->assertUnauthorized();
    }

    public function test_api_login_returns_json_validation_errors_and_is_rate_limited(): void
    {
        RateLimiter::clear(sha1('|127.0.0.1'));

        $this->post('/api/v1/login', [
            'email' => 'unknown@example.com',
            'password' => 'incorrect',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', [
                'email' => 'unknown@example.com',
                'password' => 'incorrect',
            ])->assertUnprocessable();
        }

        $tooManyAttempts = $this->postJson('/api/v1/login', [
            'email' => 'unknown@example.com',
            'password' => 'incorrect',
        ]);
        $tooManyAttempts->assertStatus(429);
        $this->assertStringContainsString(
            'application/json',
            (string) $tooManyAttempts->headers->get('content-type')
        );
    }

    public function test_api_routes_allow_cross_origin_requests(): void
    {
        $this->call('OPTIONS', '/api/v1/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://client.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
