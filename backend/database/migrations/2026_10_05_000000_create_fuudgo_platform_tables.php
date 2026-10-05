<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('role', 20)->default('customer')->index()->after('password');
        });

        Schema::create('restaurants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('cuisines');
            $table->json('tags')->nullable();
            $table->string('promotion_badge')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('logo_image')->nullable();
            $table->string('area')->default('Kuching City Centre')->index();
            $table->json('operating_hours')->nullable();
            $table->unsignedSmallInteger('delivery_minutes')->default(35);
            $table->unsignedSmallInteger('pickup_minutes')->default(20);
            $table->unsignedInteger('delivery_fee_cents')->default(300);
            $table->unsignedInteger('minimum_order_cents')->default(0);
            $table->unsignedInteger('free_delivery_threshold_cents')->nullable();
            $table->decimal('rating', 2, 1)->default(4.5);
            $table->unsignedInteger('review_count')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->boolean('featured')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['restaurant_id', 'name']);
        });

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('promotional_price_cents')->nullable();
            $table->string('image')->nullable();
            $table->json('dietary_tags')->nullable();
            $table->boolean('available')->default(true)->index();
            $table->boolean('popular')->default(false);
            $table->boolean('featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['restaurant_id', 'slug']);
            $table->index(['restaurant_id', 'available', 'sort_order']);
        });

        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('required')->default(false);
            $table->unsignedTinyInteger('minimum_selections')->default(0);
            $table->unsignedTinyInteger('maximum_selections')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('modifier_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('price_adjustment_cents')->default(0);
            $table->boolean('available')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->default('Home');
            $table->string('recipient_name');
            $table->string('phone', 32);
            $table->string('line_1');
            $table->string('line_2')->nullable();
            $table->string('area');
            $table->string('postcode', 12)->nullable();
            $table->string('city')->default('Kuching');
            $table->string('state')->default('Sarawak');
            $table->text('delivery_instructions')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });

        Schema::create('favourites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'restaurant_id']);
        });

        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 24);
            $table->unsignedInteger('value');
            $table->unsignedInteger('minimum_spend_cents')->default(0);
            $table->unsignedInteger('maximum_discount_cents')->nullable();
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedSmallInteger('uses_per_customer')->default(1);
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fulfilment', 12);
            $table->string('status', 24)->default('pending')->index();
            $table->string('payment_method', 24);
            $table->string('payment_status', 20)->default('pending')->index();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 32);
            $table->json('delivery_address')->nullable();
            $table->text('customer_note')->nullable();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->timestamp('placed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->unsignedInteger('base_unit_price_cents');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('line_total_cents');
            $table->json('modifiers_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('cash');
            $table->string('provider_reference')->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('amount_cents');
            $table->json('provider_metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('discount_cents');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['promotion_id', 'user_id']);
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('favourites');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
        Schema::dropIfExists('restaurants');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'role']);
        });
    }
};
