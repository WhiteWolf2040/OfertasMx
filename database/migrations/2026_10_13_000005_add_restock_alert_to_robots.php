<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robots', function (Blueprint $table): void {
            $table->boolean('notify_on_restock')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('robots', function (Blueprint $table): void {
            $table->dropColumn('notify_on_restock');
        });
    }
};
