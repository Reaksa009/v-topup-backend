<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->index(['status', 'order_index']);
            $table->index('is_popular');
            $table->index('is_featured');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->index('game_id');
            $table->index('is_active');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('transaction_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['status', 'order_index']);
            $table->dropIndex(['is_popular']);
            $table->dropIndex(['is_featured']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['game_id']);
            $table->dropIndex(['is_active']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['transaction_no']);
        });
    }
};
