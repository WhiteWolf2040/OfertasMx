<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_robot', function (Blueprint $table): void {
            $table->string('key', 80)->nullable()->after('type');
            $table->text('value')->nullable()->after('key');
            $table->text('previous_value')->nullable()->after('value');
            $table->string('label', 120)->nullable()->after('previous_value');
            $table->index(['robot_id', 'type', 'key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications_robot', function (Blueprint $table): void {
            $table->dropIndex(['robot_id', 'type', 'key', 'created_at']);
            $table->dropColumn(['key', 'value', 'previous_value', 'label']);
        });
    }
};
