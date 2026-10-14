<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoDealsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoDealsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_does_not_touch_robot_data_or_user_one(): void
    {
        $admin = User::forceCreate([
            'id' => 1,
            'name' => 'Diego',
            'email' => 'diego23@test.com',
            'password' => Hash::make('secret-password'),
        ]);
        DB::table('categories')->insert([
            'name' => 'Nombre existente que debe conservarse',
            'slug' => 'tecnologia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $protectedCounts = $this->protectedTableCounts();
        $adminBefore = $admin->fresh()->only(['id', 'name', 'email', 'password']);

        Artisan::call('db:seed', ['--class' => DemoDealsSeeder::class]);
        $this->assertStringContainsString('robots: 0 tocados', Artisan::output());
        $firstCounts = $this->marketplaceCounts();
        $firstDealCount = DB::table('deals')->count();

        Artisan::call('db:seed', ['--class' => DemoDealsSeeder::class]);
        $secondCounts = $this->marketplaceCounts();

        $this->assertSame($firstCounts, $secondCounts);
        $this->assertSame(48, $firstDealCount);
        $this->assertSame(48, DB::table('deals')->count());
        $this->assertSame($protectedCounts, $this->protectedTableCounts());
        $this->assertSame($adminBefore, $admin->fresh()->only(['id', 'name', 'email', 'password']));
        $this->assertSame(6, User::query()->where('email', 'like', 'demo%@ofertasmx.test')->count());
        $this->assertSame(7, DB::table('categories')->count());
        $this->assertSame(
            'Nombre existente que debe conservarse',
            DB::table('categories')->where('slug', 'tecnologia')->value('name'),
        );
        $this->assertSame(24, DB::table('deals')->whereNotNull('expires_at')->count());
        $this->assertSame(24, DB::table('deals')->whereNull('expires_at')->count());
        $this->assertSame(48, DB::table('deals')->where('currency', 'MXN')->count());
        $this->assertSame(48, DB::table('deals')->where('url', 'like', '%?demo=%')->pluck('url')->unique()->count());

        $temperatures = DB::table('deals')->pluck('id')->map(
            static fn (int $dealId): int => (int) DB::table('votes')->where('deal_id', $dealId)->sum('value'),
        );
        $this->assertTrue($temperatures->contains(static fn (int $temperature): bool => $temperature >= 4));
        $this->assertTrue($temperatures->contains(static fn (int $temperature): bool => $temperature >= 1 && $temperature <= 3));
        $this->assertTrue($temperatures->contains(0));
        $this->assertTrue($temperatures->contains(static fn (int $temperature): bool => $temperature < 0));

        foreach (DB::table('deals')->get(['price', 'original_price']) as $deal) {
            $this->assertGreaterThan((float) $deal->price, (float) $deal->original_price);
        }
        foreach (DB::table('comments')
            ->join('deals', 'comments.deal_id', '=', 'deals.id')
            ->get(['comments.created_at as comment_created_at', 'deals.created_at as deal_created_at']) as $comment) {
            $this->assertGreaterThan($comment->deal_created_at, $comment->comment_created_at);
        }
    }

    /**
     * @return array<string, int>
     */
    private function marketplaceCounts(): array
    {
        return [
            'users' => DB::table('users')->where('email', 'like', 'demo%@ofertasmx.test')->count(),
            'deals' => DB::table('deals')->count(),
            'votes' => DB::table('votes')->count(),
            'comments' => DB::table('comments')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function protectedTableCounts(): array
    {
        return [
            'robots' => DB::table('robots')->count(),
            'robot_runs' => DB::table('robot_runs')->count(),
            'store_profiles' => DB::table('store_profiles')->count(),
            'notifications_robot' => DB::table('notifications_robot')->count(),
            'personal_access_tokens' => DB::table('personal_access_tokens')->count(),
        ];
    }
}
