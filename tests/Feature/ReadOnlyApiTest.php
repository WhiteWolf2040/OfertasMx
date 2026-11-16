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

    public function test_authenticated_user_gets_the_same_permanent_read_only_api_link(): void
    {
        $user = User::factory()->create();
        $loginToken = $user->createToken('login')->plainTextToken;

        $response = $this->withToken($loginToken)
            ->postJson('/api/v1/api-link');

        $response->assertOk()
            ->assertJsonStructure(['link', 'token'])
            ->assertJsonMissingPath('expires_at')
            ->assertHeader('Cache-Control', 'no-store, private');

        $plainTextToken = $response->json('token');
        $storedToken = $user->tokens()->where('name', 'api-link')->firstOrFail();
        $this->assertSame(['read-only'], $storedToken->abilities);
        $this->assertNull($storedToken->expires_at);
        $this->assertNotSame($plainTextToken, $storedToken->token);
        $this->assertNotSame($plainTextToken, $user->fresh()->getRawOriginal('api_link_token'));

        parse_str(
            (string) parse_url($response->json('link'), PHP_URL_QUERY),
            $linkQuery
        );
        $this->assertSame($plainTextToken, $linkQuery['token']);
        $this->assertSame(
            config('app.url').'/api/v1?token='.rawurlencode($plainTextToken),
            $response->json('link')
        );

        $repeatedResponse = $this->withToken($loginToken)
            ->postJson('/api/v1/api-link');
        $repeatedResponse->assertOk()
            ->assertExactJson($response->json());
        $this->assertSame(1, $user->tokens()->where('name', 'api-link')->count());
    }

    public function test_read_only_api_link_returns_resource_index_and_preserves_pagination(): void
    {
        Category::create(['name' => 'Hogar', 'slug' => 'hogar']);
        Category::create(['name' => 'Moda', 'slug' => 'moda']);
        $user = User::factory()->create();
        $shareToken = $user->createToken('api-link', ['read-only'])->plainTextToken;
        $encodedToken = rawurlencode($shareToken);

        $firstPage = $this->getJson(
            '/api/v1/categories?limit=1&offset=0&token='.$encodedToken
        );
        $firstPage->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('results.0.name', 'Hogar')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        parse_str(
            (string) parse_url($firstPage->json('next'), PHP_URL_QUERY),
            $nextQuery
        );
        $this->assertSame($shareToken, $nextQuery['token']);
        $this->assertSame('1', $nextQuery['offset']);

        $secondPage = $this->getJson(
            '/api/v1/categories?limit=1&offset=1&token='.$encodedToken
        );
        $secondPage->assertOk()
            ->assertJsonPath('results.0.name', 'Moda');

        parse_str(
            (string) parse_url($secondPage->json('previous'), PHP_URL_QUERY),
            $previousQuery
        );
        $this->assertSame($shareToken, $previousQuery['token']);
        $this->assertSame('0', $previousQuery['offset']);

        $resourceIndex = $this->getJson('/api/v1?token='.$encodedToken);
        $resourceIndex->assertOk()
            ->assertJsonCount(8)
            ->assertJsonMissingPath('users')
            ->assertHeader('Cache-Control', 'no-store, private');
        foreach ([
            'categories',
            'deals',
            'comments',
            'votes',
            'robots',
            'robot-runs',
            'store-profiles',
            'robot-notifications',
        ] as $resource) {
            $this->assertSame($shareToken, $this->queryToken($resourceIndex->json($resource)));
        }

        $this->withToken($shareToken)
            ->getJson('/api/v1')
            ->assertOk()
            ->assertJsonMissingPath('users');
        $this->withToken($shareToken)
            ->getJson('/api/v1/users')
            ->assertUnauthorized();
        $this->getJson('/api/v1/users?token='.$encodedToken)
            ->assertUnauthorized();
        $this->withToken($shareToken)
            ->getJson('/api/v1/users/1')
            ->assertUnauthorized();

        $ordinaryToken = $user->createToken('ordinary')->plainTextToken;
        $this->getJson('/api/v1/categories?token='.rawurlencode($ordinaryToken))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
        $this->withToken($ordinaryToken)
            ->getJson('/api/v1')
            ->assertUnauthorized();
        $this->getJson('/api/me?token='.rawurlencode($shareToken))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $expiredToken = $user->createToken(
            'expired-share',
            ['read-only'],
            now()->subSecond()
        )->plainTextToken;
        $this->getJson('/api/v1/categories?token='.rawurlencode($expiredToken))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_read_only_all_returns_100_records_and_tokenized_next_links(): void
    {
        for ($index = 1; $index <= 101; $index++) {
            Category::create([
                'name' => 'Categoría '.$index,
                'slug' => 'categoria-'.$index,
            ]);
        }

        $user = User::factory()->create();
        $shareToken = $user->createToken('api-link', ['read-only'])->plainTextToken;
        $encodedToken = rawurlencode($shareToken);

        $response = $this->getJson('/api/v1/all?token='.$encodedToken);
        $response->assertOk()
            ->assertJsonCount(8)
            ->assertJsonPath('categories.count', 101)
            ->assertJsonCount(100, 'categories.results')
            ->assertJsonMissingPath('users')
            ->assertJsonMissingPath('categories.results.0.password')
            ->assertJsonMissingPath('categories.results.0.remember_token');

        foreach ([
            'categories',
            'deals',
            'comments',
            'votes',
            'robots',
            'robot-runs',
            'store-profiles',
            'robot-notifications',
        ] as $resource) {
            $this->assertArrayHasKey('count', $response->json($resource));
            $this->assertArrayHasKey('results', $response->json($resource));
            $this->assertArrayHasKey('next', $response->json($resource));
        }

        $nextUrl = $response->json('categories.next');
        $this->assertSame(
            'api/v1/all',
            trim((string) parse_url($nextUrl, PHP_URL_PATH), '/')
        );
        parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
        $this->assertSame('100', $nextQuery['limit']);
        $this->assertSame('100', $nextQuery['offset']);
        $this->assertSame($shareToken, $nextQuery['token']);

        $nextPage = $this->getJson('/api/v1/all?limit=100&offset=100&token='.$encodedToken);
        $nextPage->assertOk()
            ->assertJsonCount(1, 'categories.results')
            ->assertJsonPath('categories.next', null);

        $this->getJson('/api/v1/all?limit=101&token='.$encodedToken)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        $ordinaryToken = $user->createToken('ordinary')->plainTextToken;
        $this->withToken($ordinaryToken)
            ->getJson('/api/v1/all')
            ->assertUnauthorized();
        $this->getJson('/api/v1/all')
            ->assertUnauthorized();
    }

    public function test_api_link_can_be_regenerated_and_revoked(): void
    {
        $user = User::factory()->create();
        $loginToken = $user->createToken('login')->plainTextToken;

        $firstLink = $this->withToken($loginToken)
            ->postJson('/api/v1/api-link')
            ->assertOk()
            ->json('token');
        $firstTokenId = $user->tokens()->where('name', 'api-link')->value('id');

        $newLinkResponse = $this->withToken($loginToken)
            ->postJson('/api/v1/api-link/regenerate');
        $newLinkResponse->assertOk();
        $newToken = $newLinkResponse->json('token');
        $this->assertNotSame($firstLink, $newToken);
        $this->assertSame(1, $user->tokens()->where('name', 'api-link')->count());

        $this->getJson('/api/v1?token='.rawurlencode($firstLink), ['Authorization' => ''])
            ->assertUnauthorized();
        $this->withToken($newToken)
            ->getJson('/api/v1')
            ->assertOk();

        $this->withToken($loginToken)
            ->deleteJson('/api/v1/api-link')
            ->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstTokenId]);
        $this->assertNull($user->fresh()->api_link_token);
        $this->getJson('/api/v1?token='.rawurlencode($newToken), ['Authorization' => ''])
            ->assertUnauthorized();
    }

    public function test_permanent_api_link_ignores_sanctum_global_expiration(): void
    {
        config(['sanctum.expiration' => 1]);
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('api-link', ['read-only'])->plainTextToken;

        $this->travel(2)->minutes();

        $this->withToken($plainTextToken)
            ->getJson('/api/v1')
            ->assertOk();

        $this->travelBack();
    }

    public function test_read_only_api_link_cannot_access_write_methods(): void
    {
        $user = User::factory()->create();
        $shareToken = $user->createToken('api-link', ['read-only'])->plainTextToken;

        $this->postJson('/api/v1/api-link?token='.rawurlencode($shareToken))
            ->assertUnauthorized();
        $this->withToken($shareToken)->postJson('/api/v1/api-link')
            ->assertUnauthorized();
        $this->withToken($shareToken)->postJson('/api/v1/api-link/regenerate')
            ->assertUnauthorized();
        $this->withToken($shareToken)->postJson('/api/deals', [])
            ->assertUnauthorized();
        $this->withToken($shareToken)->putJson('/api/deals/999', [])
            ->assertUnauthorized();
        $this->withToken($shareToken)->patchJson('/api/robots/999', [])
            ->assertUnauthorized();
        $this->withToken($shareToken)->deleteJson('/api/deals/999')
            ->assertUnauthorized();
    }

    public function test_api_link_operations_share_a_five_per_minute_rate_limit(): void
    {
        $user = User::factory()->create();
        $loginToken = $user->createToken('login')->plainTextToken;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withToken($loginToken)
                ->postJson('/api/v1/api-link')
                ->assertOk();
        }

        $this->withToken($loginToken)
            ->deleteJson('/api/v1/api-link')
            ->assertStatus(429);
    }

    private function queryToken(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['token'] ?? null;
    }
}
