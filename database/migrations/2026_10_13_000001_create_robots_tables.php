<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->text('url');
            $table->unsignedSmallInteger('interval_minutes')->default(10);
            $table->boolean('is_active')->default(true);
            $table->decimal('last_price', 12, 2)->nullable();
            $table->string('last_currency', 10)->nullable();
            $table->string('last_status', 20)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('robot_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('robot_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('provider')->nullable();
            $table->json('data')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['robot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_runs');
        Schema::dropIfExists('robots');
    }
};
