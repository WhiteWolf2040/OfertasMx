<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MarketplaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_and_logout_with_a_token(): void
    {
        $registration = $this->postJson('/api/register', [
            'name' => 'Diego',
            'email' => 'diego@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $registration->assertCreated()->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $login = $this->postJson('/api/login', [
            'email' => 'diego@example.com',
            'password' => 'password123',
        ]);
        $login->assertOk()->assertJsonStructure(['user', 'token']);

        $token = $login->json('token');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('user.email', 'diego@example.com');
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_registration_and_login_return_clear_validation_errors(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Diego',
            'email' => 'invalid',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->postJson('/api/login', [
            'email' => 'unknown@example.com',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_guest_cannot_publish_and_authenticated_user_can_publish(): void
    {
        $category = Category::create(['name' => 'Tecnología', 'slug' => 'tecnologia']);
        $payload = $this->dealPayload($category);

        $this->postJson('/api/deals', $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/deals', $payload)
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('category.id', $category->id)
            ->assertJsonPath('currency', 'MXN');
    }

    public function test_my_deals_contains_only_the_authenticated_users_deals(): void
    {
        $category = Category::create(['name' => 'Hogar', 'slug' => 'hogar']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownedDeal = $owner->deals()->create($this->dealPayload($category, 'Mi oferta'));
        $other->deals()->create($this->dealPayload($category, 'Otra oferta'));

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/my-deals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedDeal->id);
    }

    public function test_only_the_deal_owner_can_update_or_delete_a_deal(): void
    {
        $category = Category::create(['name' => 'Moda', 'slug' => 'moda']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deal = $owner->deals()->create($this->dealPayload($category));

        $this->actingAs($other, 'sanctum')
            ->putJson('/api/deals/'.$deal->id, ['title' => 'Alterada'])
            ->assertForbidden();
        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/deals/'.$deal->id)
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/deals/'.$deal->id, ['title' => 'Actualizada'])
            ->assertOk()
            ->assertJsonPath('title', 'Actualizada');
        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/deals/'.$deal->id)
            ->assertOk();
        $this->assertDatabaseMissing('deals', ['id' => $deal->id]);
    }

    public function test_deal_listing_supports_categories_search_and_vote_sorting(): void
    {
        $tech = Category::create(['name' => 'Tecnología', 'slug' => 'tecnologia']);
        $home = Category::create(['name' => 'Hogar', 'slug' => 'hogar']);
        $user = User::factory()->create();
        $first = $user->deals()->create($this->dealPayload($tech, 'Laptop en oferta'));
        $user->deals()->create($this->dealPayload($home, 'Silla cómoda'));

        $this->getJson('/api/deals?category=tecnologia&search=Laptop')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.user.name', $user->name)
            ->assertJsonPath('data.0.category.name', 'Tecnología')
            ->assertJsonPath('data.0.temperature', 0);

        $this->getJson('/api/categories')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/deals/'.$first->id)->assertOk()->assertJsonPath('id', $first->id);
    }

    public function test_scrape_requires_an_authenticated_user(): void
    {
        $this->postJson('/api/scrape', ['url' => 'https://shop.example/product'])->assertUnauthorized();
    }

    private function dealPayload(Category $category, string $title = 'Oferta'): array
    {
        return [
            'title' => $title,
            'url' => 'https://shop.example/product',
            'price' => 99.90,
            'currency' => 'MXN',
            'store' => 'Tienda ejemplo',
            'category_id' => $category->id,
            'description' => 'Descripción de prueba',
        ];
    }
}
