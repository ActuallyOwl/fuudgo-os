<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FuudgoPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalogue_lists_seeded_kuching_restaurants_and_real_modifiers(): void
    {
        $this->seed(\Database\Seeders\FuudgoDemoSeeder::class);

        $this->getJson('/api/restaurants')->assertOk()->assertJsonPath('meta.total', 20);
        $this->getJson('/api/restaurants/lidah-sarawak-kitchen')
            ->assertOk()
            ->assertJsonPath('data.name', 'Lidah Sarawak Kitchen')
            ->assertJsonPath('data.categories.0.items.0.name', 'Sarawak Laksa')
            ->assertJsonPath('data.categories.0.items.0.modifierGroups.0.options.1.name', 'Extra prawn');
    }

    public function test_customers_cannot_access_admin_endpoints_and_admins_can(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();

        $customer = User::factory()->create();
        Sanctum::actingAs($customer->refresh());
        $this->getJson('/api/admin/dashboard')->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.registeredCustomers', 1);
    }

    public function test_registration_hashes_the_password_and_creates_a_customer_session(): void
    {
        $payload = ['name' => 'Aina Customer', 'email' => 'aina@example.test', 'phone' => '0123456789', 'password' => 'FuudgoStrong12', 'password_confirmation' => 'FuudgoStrong12'];
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/register', $payload)->assertCreated()->assertJsonPath('user.role', 'customer');

        $user = User::where('email', 'aina@example.test')->firstOrFail();
        $this->assertNotSame('FuudgoStrong12', $user->password);
        $this->assertSame('customer', $user->role);
    }

    public function test_checkout_uses_database_prices_modifiers_and_server_side_promotion_rules(): void
    {
        [$restaurant, $item, $group, $option, $sizeGroup, $sizeOption] = $this->makeRestaurantWithModifiers();
        $customer = User::factory()->create();
        $promotion = Promotion::create(['code' => 'TENOFF', 'title' => 'Ten percent off', 'type' => 'percentage', 'value' => 10, 'minimum_spend_cents' => 3000, 'maximum_discount_cents' => 800, 'active' => true, 'uses_per_customer' => 1]);
        Sanctum::actingAs($customer->refresh());

        $this->postJson('/api/orders', [
            'fulfilment' => 'delivery',
            'payment_method' => 'cash_on_delivery',
            'customer_phone' => '0123456789',
            'delivery_address' => ['line_1' => '12 River Road', 'area' => 'Satok'],
            'promo_code' => 'TENOFF',
            'frontend_total' => 1,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 2, 'modifiers' => [['group_id' => $group->id, 'option_ids' => [$option->id]], ['group_id' => $sizeGroup->id, 'option_ids' => [$sizeOption->id]]]]],
        ])->assertCreated()->assertJsonPath('data.subtotal', 48)->assertJsonPath('data.discount', 4.8)->assertJsonPath('data.total', 43.2);

        $order = Order::with('items')->firstOrFail();
        $this->assertSame(4800, $order->subtotal_cents);
        $this->assertSame(4320, $order->total_cents);
        $this->assertSame(2000, $order->items->first()->base_unit_price_cents);
        $this->assertSame(2400, $order->items->first()->unit_price_cents);
        $this->assertSame('Extra prawn', $order->items->first()->modifiers_snapshot[0]['name']);
        $this->assertDatabaseHas('promotion_usages', ['promotion_id' => $promotion->id, 'user_id' => $customer->id, 'discount_cents' => 480]);
    }

    public function test_checkout_rejects_missing_required_modifiers_and_cross_restaurant_baskets(): void
    {
        [$restaurant, $item, $group] = $this->makeRestaurantWithModifiers();
        $customer = User::factory()->create();
        Sanctum::actingAs($customer->refresh());
        $payload = ['fulfilment' => 'pickup', 'payment_method' => 'cash_on_pickup', 'customer_phone' => '0123456789', 'items' => [['menu_item_id' => $item->id, 'quantity' => 1]]];
        $this->postJson('/api/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');

        $otherRestaurant = Restaurant::create(['name' => 'Second Bite', 'slug' => 'second-bite', 'cuisines' => ['Local'], 'area' => 'Satok', 'status' => 'active']);
        $category = MenuCategory::create(['restaurant_id' => $otherRestaurant->id, 'name' => 'Noodles']);
        $second = MenuItem::create(['restaurant_id' => $otherRestaurant->id, 'menu_category_id' => $category->id, 'name' => 'Mee', 'slug' => 'mee', 'price_cents' => 1000, 'available' => true]);
        $payload['items'][] = ['menu_item_id' => $second->id, 'quantity' => 1];
        $this->postJson('/api/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_free_delivery_promotion_offsets_the_fee_once_and_admin_can_advance_order_status(): void
    {
        [$restaurant, $item, $group, $option, $sizeGroup, $sizeOption] = $this->makeRestaurantWithModifiers();
        $customer = User::factory()->create();
        Promotion::create(['code' => 'DELIVERY', 'title' => 'Free delivery', 'type' => 'free_delivery', 'value' => 0, 'minimum_spend_cents' => 0, 'active' => true, 'uses_per_customer' => 1]);
        Sanctum::actingAs($customer->refresh());
        $response = $this->postJson('/api/orders', [
            'fulfilment' => 'delivery',
            'payment_method' => 'cash_on_delivery',
            'customer_phone' => '0123456789',
            'delivery_address' => ['line_1' => '12 River Road', 'area' => 'Satok'],
            'promo_code' => 'DELIVERY',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1, 'modifiers' => [
                ['group_id' => $group->id, 'option_ids' => []],
                ['group_id' => $sizeGroup->id, 'option_ids' => [$sizeOption->id]],
            ]]],
        ])->assertCreated()->assertJsonPath('data.subtotal', 20)->assertJsonPath('data.deliveryFee', 2.5)->assertJsonPath('data.discount', 2.5)->assertJsonPath('data.total', 20);
        $reference = $response->json('data.reference');
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin->refresh());
        $this->patchJson("/api/admin/orders/{$reference}/status", ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->patchJson("/api/admin/orders/{$reference}/status", ['status' => 'preparing'])->assertOk()->assertJsonPath('data.status', 'preparing');
        $this->patchJson("/api/admin/orders/{$reference}/status", ['status' => 'rider_assigned'])->assertOk()->assertJsonPath('data.status', 'rider_assigned');
        $this->patchJson("/api/admin/orders/{$reference}/status", ['status' => 'picked_up'])->assertOk()->assertJsonPath('data.status', 'picked_up');
        $this->patchJson("/api/admin/orders/{$reference}/status", ['status' => 'on_the_way'])->assertOk()->assertJsonPath('data.status', 'on_the_way');
        $this->patchJson("/api/admin/orders/{$reference}/status", ['status' => 'delivered'])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');
        $this->assertDatabaseHas('order_status_histories', ['order_id' => Order::where('reference', $reference)->value('id'), 'to_status' => 'delivered']);
    }

    public function test_customer_and_admin_login_sessions_are_separate_and_logout_works(): void
    {
        $customer = User::factory()->create(['email' => 'customer@example.test', 'password' => Hash::make('FuudgoStrong12')]);
        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/login', ['email' => $customer->email, 'password' => 'wrong-password'])
            ->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $customer->email, 'password' => 'FuudgoStrong12'])
            ->assertOk()->assertJsonPath('user.role', 'customer');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.email', $customer->email);
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/admin/login', ['email' => $customer->email, 'password' => 'FuudgoStrong12'])->assertForbidden();
        $admin = User::factory()->create(['email' => 'admin@example.test', 'password' => Hash::make('FuudgoStrong12')]);
        $admin->forceFill(['role' => 'admin'])->save();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'FuudgoStrong12'])
            ->assertOk()->assertJsonPath('user.role', 'admin');
    }

    private function makeRestaurantWithModifiers(): array
    {
        $restaurant = Restaurant::create(['name' => 'Laksa House', 'slug' => 'laksa-house', 'cuisines' => ['Sarawak'], 'area' => 'Satok', 'delivery_fee_cents' => 250, 'minimum_order_cents' => 0, 'free_delivery_threshold_cents' => 3500, 'status' => 'active']);
        $category = MenuCategory::create(['restaurant_id' => $restaurant->id, 'name' => 'Local favourites']);
        $item = MenuItem::create(['restaurant_id' => $restaurant->id, 'menu_category_id' => $category->id, 'name' => 'Sarawak Laksa', 'slug' => 'sarawak-laksa', 'price_cents' => 2000, 'available' => true]);
        $group = ModifierGroup::create(['menu_item_id' => $item->id, 'name' => 'Extras', 'required' => false, 'minimum_selections' => 0, 'maximum_selections' => 2, 'active' => true]);
        $option = $group->options()->create(['name' => 'Extra prawn', 'price_adjustment_cents' => 400, 'available' => true]);
        $size = ModifierGroup::create(['menu_item_id' => $item->id, 'name' => 'Portion', 'required' => true, 'minimum_selections' => 1, 'maximum_selections' => 1, 'active' => true]);
        $sizeOption = $size->options()->create(['name' => 'Regular', 'price_adjustment_cents' => 0, 'available' => true]);

        return [$restaurant, $item, $group, $option, $size, $sizeOption];
    }
}
