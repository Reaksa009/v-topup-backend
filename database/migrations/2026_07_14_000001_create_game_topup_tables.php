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
        // 1. Categories
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_kh');
            $table->string('slug')->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        // 2. Games
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->string('name_en');
            $table->string('name_kh');
            $table->string('slug')->unique();
            $table->string('logo_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_kh')->nullable();
            $table->string('player_id_label_en')->default('Player ID');
            $table->string('player_id_label_kh')->default('លេខសម្គាល់អ្នកលេង (Player ID)');
            $table->boolean('server_id_required')->default(false);
            $table->string('server_id_label_en')->nullable();
            $table->string('server_id_label_kh')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('order_index')->default(0);
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });

        // 3. Packages
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->string('name_en');
            $table->string('name_kh');
            $table->decimal('price_usd', 10, 2);
            $table->integer('price_khr');
            $table->decimal('original_price_usd', 10, 2)->nullable();
            $table->integer('points_or_diamonds');
            $table->integer('bonus_points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 4. Coupons
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type'); // fixed, percentage
            $table->decimal('value', 10, 2);
            $table->decimal('min_spend', 10, 2)->default(0.00);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->integer('limit_per_user')->default(1);
            $table->integer('usage_count')->default(0);
            $table->timestamps();
        });

        // 5. Orders
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('order_no')->unique();
            $table->foreignId('game_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('package_id')->nullable()->constrained()->onDelete('set null');
            
            // Snapshots to keep record even if game/package is deleted
            $table->string('game_name');
            $table->string('package_name');
            
            $table->string('player_id');
            $table->string('server_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('original_price_usd', 10, 2);
            $table->decimal('price_usd', 10, 2);
            $table->decimal('discount_usd', 10, 2)->default(0.00);
            $table->decimal('total_price_usd', 10, 2);
            $table->integer('total_price_khr');
            $table->string('status')->default('pending'); // pending, waiting_verification, paid, processing, completed, cancelled, refunded
            $table->string('payment_method'); // khqr_bakong, aba_qr, wing
            $table->string('coupon_code')->nullable();
            $table->timestamps();
        });

        // 6. Payments
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->index();
            $table->string('transaction_no');
            $table->decimal('amount_usd', 10, 2);
            $table->integer('amount_khr');
            $table->string('payment_method');
            $table->string('receipt_image_url');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('status')->default('pending'); // pending, verified, rejected
            $table->timestamps();
        });

        // 7. Banners
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title_en');
            $table->string('title_kh');
            $table->string('image_url');
            $table->string('link_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order_index')->default(0);
            $table->timestamps();
        });

        // 8. News
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title_en');
            $table->string('title_kh');
            $table->text('content_en')->nullable();
            $table->text('content_kh')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->integer('views')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        // 9. Activity Logs
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // 10. Settings
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('news');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('games');
        Schema::dropIfExists('categories');
    }
};
