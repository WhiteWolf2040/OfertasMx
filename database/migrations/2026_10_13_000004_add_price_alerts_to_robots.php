<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robots', function (Blueprint $table): void {
            $table->decimal('target_price', 12, 2)->nullable();
            $table->boolean('notify_on_drop')->default(false);
            $table->timestamp('alerted_at')->nullable();
        });

        Schema::create('notifications_robot', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('price', 12, 2);
            $table->decimal('previous_price', 12, 2)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['robot_id', 'type', 'price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_robot');

        Schema::table('robots', function (Blueprint $table): void {
            $table->dropColumn(['target_price', 'notify_on_drop', 'alerted_at']);
        });
    }
};
