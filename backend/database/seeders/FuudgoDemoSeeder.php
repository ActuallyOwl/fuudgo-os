<?php

namespace Database\Seeders;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Promotion;
use App\Models\Restaurant;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class FuudgoDemoSeeder extends Seeder
{
    public function run(): void
    {
        $restaurants = json_decode(File::get(database_path('seeders/fuudgo-demo-data.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($restaurants as $entry) {
            $restaurant = Restaurant::updateOrCreate(['slug' => $entry['slug']], [
                'name' => $entry['name'],
                'description' => 'Thoughtful local favourites, made fresh for your table.',
                'cuisines' => $entry['cuisine'],
                'tags' => $entry['tags'],
                'promotion_badge' => $entry['promotion'] ?? null,
                'cover_image' => $entry['image'],
                'area' => $entry['tags'][0],
                'delivery_minutes' => $entry['deliveryMinutes'],
                'pickup_minutes' => $entry['pickupMinutes'],
                'delivery_fee_cents' => (int) round($entry['deliveryFee'] * 100),
                'minimum_order_cents' => (int) round($entry['minimum'] * 100),
                'free_delivery_threshold_cents' => 3500,
                'rating' => $entry['rating'],
                'review_count' => $entry['reviews'],
                'status' => 'active',
                'featured' => $entry['featured'],
            ]);

            foreach ($entry['dishes'] as $position => $dish) {
                $category = MenuCategory::firstOrNew(['restaurant_id' => $restaurant->id, 'name' => $dish['category']]);
                $category->sort_order = min($category->sort_order ?? $position, $position);
                $category->active = true;
                $category->save();
                $item = MenuItem::withTrashed()->updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'slug' => $dish['id']],
                    [
                        'menu_category_id' => $category->id,
                        'name' => $dish['name'],
                        'description' => $dish['description'],
                        'price_cents' => (int) round($dish['price'] * 100),
                        'promotional_price_cents' => isset($dish['promoPrice']) ? (int) round($dish['promoPrice'] * 100) : null,
                        'image' => $dish['image'],
                        'dietary_tags' => [],
                        'available' => $dish['available'],
                        'popular' => $dish['popular'] ?? false,
                        'featured' => false,
                        'sort_order' => $position,
                    ],
                );
                if ($item->trashed()) {
                    $item->restore();
                }

                if ($item->modifierGroups()->exists()) {
                    continue;
                }

                if ($dish['id'] === 'laksa') {
                    $this->makeModifiers($item, 'Extras', 0, 2, [['Extra egg', 200], ['Extra prawn', 400]]);
                } elseif ($dish['id'] === 'kolo') {
                    $this->makeModifiers($item, 'Noodle size', 1, 1, [['Regular', 0], ['Large', 200]]);
                    $this->makeModifiers($item, 'Add-ons', 0, 3, [['Extra meat', 350], ['Extra vegetables', 100]]);
                }
            }
        }

        Promotion::updateOrCreate(['code' => 'KCH10'], [
            'title' => 'A little off your first order',
            'description' => 'Save 10% on orders over RM30, up to RM8 off.',
            'type' => 'percentage',
            'value' => 10,
            'minimum_spend_cents' => 3000,
            'maximum_discount_cents' => 800,
            'active' => true,
            'uses_per_customer' => 1,
        ]);
        Promotion::updateOrCreate(['code' => 'KCHDELIVERY'], [
            'title' => 'Free delivery around Kuching',
            'description' => 'Delivery is on FuudGo for orders over RM35.',
            'type' => 'free_delivery',
            'value' => 0,
            'minimum_spend_cents' => 3500,
            'active' => true,
            'uses_per_customer' => 3,
        ]);

        foreach ([
            'ordering_enabled' => true,
            'cod_enabled' => true,
            'online_payments_enabled' => false,
            'default_currency' => 'MYR',
            'support_email' => null,
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => ['value' => $value]]);
        }
    }

    private function makeModifiers(MenuItem $item, string $name, int $minimum, int $maximum, array $options): void
    {
        $group = ModifierGroup::create([
            'menu_item_id' => $item->id,
            'name' => $name,
            'required' => $minimum > 0,
            'minimum_selections' => $minimum,
            'maximum_selections' => $maximum,
            'sort_order' => $item->modifierGroups()->count(),
            'active' => true,
        ]);

        foreach ($options as $position => [$option, $price]) {
            $group->options()->create(['name' => $option, 'price_adjustment_cents' => $price, 'available' => true, 'sort_order' => $position]);
        }
    }
}
