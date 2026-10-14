<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (env('SEED_DEMO', false)) {
            $this->call(DemoDealsSeeder::class);

            return;
        }

        $this->call(CategorySeeder::class);
    }
}
