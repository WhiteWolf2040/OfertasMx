<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadOnlyApiHtmlTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_negotiation_escapes_content_and_sets_security_headers(): void
    {
        $category = Category::create([
            'name' => '<script>alert(1)</script>',
            'slug' => 'safe-test',
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/categories', ['Accept' => 'text/html']);

        $response->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertHeader('Vary', 'Accept');
        $this->assertStringContainsString(
            'text/html',
            (string) $response->headers->get('Content-Type')
        );

        $content = $response->getContent();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $content);
        $this->assertStringContainsString("script-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('name="viewport"', $content);
        $this->assertStringContainsString('prefers-color-scheme: dark', $content);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/categories/'.$category->id.'?format=html', [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertSee('Categorías')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_json_format_overrides_html_accept_and_wildcard_accept_remains_json(): void
    {
        Category::create(['name' => 'Tecnología', 'slug' => 'tecnologia']);
        $user = User::factory()->create();

        $expectedJson = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/categories')
            ->getContent();

        $jsonResponse = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/categories?format=json', ['Accept' => 'text/html']);
        $jsonResponse->assertOk()
            ->assertHeader('Content-Type', 'application/json');
        $this->assertSame($expectedJson, $jsonResponse->getContent());

        $explicitJsonResponse = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/categories', ['Accept' => 'application/json']);
        $explicitJsonResponse->assertOk()
            ->assertHeader('Content-Type', 'application/json');
        $this->assertSame($expectedJson, $explicitJsonResponse->getContent());

        $wildcardResponse = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/categories', ['Accept' => '*/*']);
        $wildcardResponse->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_read_only_html_links_preserve_token_and_format_in_pagination(): void
    {
        Category::create(['name' => 'Hogar', 'slug' => 'hogar']);
        Category::create(['name' => 'Moda', 'slug' => 'moda']);
        $user = User::factory()->create();
        $token = $user->createToken('api-link', ['read-only'])->plainTextToken;
        $encodedToken = rawurlencode($token);

        $response = $this->get(
            '/api/v1/categories?limit=1&offset=0&format=html&token='.$encodedToken,
            ['Accept' => 'text/html']
        );
        $response->assertOk()
            ->assertSee('Siguiente')
            ->assertSee('Ver JSON');

        $content = html_entity_decode(
            (string) $response->getContent(),
            ENT_QUOTES | ENT_HTML5
        );
        preg_match('/<a href="([^"]+)"[^>]*>Siguiente<\/a>/', $content, $matches);
        $this->assertArrayHasKey(1, $matches);
        parse_str((string) parse_url($matches[1], PHP_URL_QUERY), $nextQuery);
        $this->assertSame($token, $nextQuery['token']);
        $this->assertSame('html', $nextQuery['format']);
        $this->assertSame('1', $nextQuery['offset']);

        $this->get(
            '/api/v1/resources?format=html&token='.$encodedToken,
            ['Accept' => 'text/html']
        )->assertOk()
            ->assertSee('Recursos disponibles')
            ->assertSee('categories?token=', false)
            ->assertDontSee('users', false);

        $this->get(
            '/api/v1/all?format=html&token='.$encodedToken,
            ['Accept' => 'text/html']
        )->assertOk()
            ->assertSee('Ofertas')
            ->assertSee('Imagen')
            ->assertDontSee('users', false);
    }

    public function test_html_only_uses_https_for_external_images_and_links(): void
    {
        $user = User::factory()->create();
        $user->deals()->create([
            'title' => '<img src=x onerror=alert(1)>',
            'url' => 'javascript:alert(1)',
            'image' => 'javascript:alert(1)',
            'price' => '25.00',
            'store' => 'Tienda',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/deals?format=html');
        $response->assertOk();

        $content = (string) $response->getContent();
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $content);
        $this->assertStringNotContainsString('href="javascript:alert(1)"', $content);
        $this->assertStringNotContainsString('src="javascript:alert(1)"', $content);
    }
}
