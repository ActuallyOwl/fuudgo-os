<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Promotion;
use App\Models\Restaurant;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function restaurants(Request $request)
    {
        $query = Restaurant::query()->where('status', 'active')->withCount('menuItems')
            ->with(['menuItems' => fn ($items) => $items->where('available', true)->with('category')]);
        $term = trim((string) $request->query('q', ''));

        if ($term !== '') {
            $query->where(function ($query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('area', 'like', "%{$term}%")
                    ->orWhere('cuisines', 'like', "%{$term}%")
                    ->orWhereHas('menuItems', fn ($items) => $items->where('available', true)->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('menuItems.category', fn ($categories) => $categories->where('name', 'like', "%{$term}%"));
            });
        }

        $area = trim((string) $request->query('area', ''));
        if ($area !== '') {
            $query->where('area', $area);
        }

        $category = trim((string) $request->query('category', ''));
        if ($category !== '' && $category !== 'All') {
            $query->where(function ($query) use ($category): void {
                $query->whereJsonContains('cuisines', $category)
                    ->orWhereHas('categories', fn ($categories) => $categories->where('active', true)->where('name', $category))
                    ->orWhereHas('menuItems', fn ($items) => $items->where('available', true)->where('name', 'like', "%{$category}%"));
            });
        }

        match ((string) $request->query('filter', '')) {
            'top-rated' => $query->where('rating', '>=', 4.5),
            'under-30' => $query->where('delivery_minutes', '<', 30),
            'free-delivery' => $query->where(function ($q): void {
                $q->where('delivery_fee_cents', 0)->orWhere('promotion_badge', 'like', 'Free delivery%');
            }),
            'deals' => $query->where(function ($q): void {
                $q->where('promotion_badge', 'like', '% off%')->orWhere('promotion_badge', 'like', 'Free delivery%');
            }),
            default => null,
        };

        match ((string) $request->query('sort', 'recommended')) {
            'rating' => $query->orderByDesc('rating')->orderBy('name'),
            'fastest' => $query->orderBy('delivery_minutes')->orderBy('name'),
            'delivery-fee' => $query->orderBy('delivery_fee_cents')->orderBy('name'),
            default => $query->orderByDesc('featured')->orderBy('name'),
        };

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(40, max(1, (int) $request->query('per_page', 24)));
        $restaurants = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $restaurants->getCollection()->map(fn (Restaurant $restaurant) => $this->formatRestaurant($restaurant)),
            'meta' => ['current_page' => $restaurants->currentPage(), 'last_page' => $restaurants->lastPage(), 'total' => $restaurants->total()],
        ]);
    }

    public function restaurant(string $slug)
    {
        $restaurant = Restaurant::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->with(['categories' => fn ($query) => $query->where('active', true), 'categories.items' => fn ($query) => $query->where('available', true)->with(['category', 'modifierGroups' => fn ($groups) => $groups->where('active', true), 'modifierGroups.options' => fn ($options) => $options->where('available', true)])])
            ->firstOrFail();

        $payload = $this->formatRestaurant($restaurant);
        $payload['categories'] = $restaurant->categories->map(fn ($category) => [
            'id' => (string) $category->id,
            'name' => $category->name,
            'sortOrder' => $category->sort_order,
            'items' => $category->items->map(fn (MenuItem $item) => $this->formatItem($item)),
        ])->values();
        $payload['dishes'] = $payload['categories']->flatMap(fn ($category) => $category['items'])->values();

        return response()->json(['data' => $payload]);
    }

    public function promotions()
    {
        return response()->json(['data' => Promotion::query()
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->get(['code', 'title', 'description', 'type', 'value', 'minimum_spend_cents', 'maximum_discount_cents'])
            ->map(fn (Promotion $promo) => [
                'code' => $promo->code,
                'title' => $promo->title,
                'description' => $promo->description,
                'type' => $promo->type,
                'value' => $promo->value,
                'minimumSpend' => $this->rm($promo->minimum_spend_cents),
                'maximumDiscount' => $promo->maximum_discount_cents === null ? null : $this->rm($promo->maximum_discount_cents),
            ])]);
    }

    private function formatRestaurant(Restaurant $restaurant): array
    {
        $dishes = $restaurant->relationLoaded('menuItems') ? $restaurant->menuItems->where('available', true) : collect();

        return [
            'id' => (string) $restaurant->id,
            'slug' => $restaurant->slug,
            'name' => $restaurant->name,
            'image' => $restaurant->cover_image,
            'logoImage' => $restaurant->logo_image,
            'description' => $restaurant->description,
            'cuisine' => $restaurant->cuisines ?? [],
            'tags' => $restaurant->tags ?? [],
            'area' => $restaurant->area,
            'rating' => (float) $restaurant->rating,
            'reviews' => $restaurant->review_count,
            'deliveryMinutes' => $restaurant->delivery_minutes,
            'pickupMinutes' => $restaurant->pickup_minutes,
            'deliveryFee' => $this->rm($restaurant->delivery_fee_cents),
            'freeDeliveryThreshold' => $restaurant->free_delivery_threshold_cents === null ? null : $this->rm($restaurant->free_delivery_threshold_cents),
            'minimum' => $this->rm($restaurant->minimum_order_cents),
            'promotion' => $restaurant->promotion_badge,
            'featured' => $restaurant->featured,
            'dishes' => $dishes->map(fn (MenuItem $item) => $this->formatItem($item))->values(),
        ];
    }

    private function formatItem(MenuItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'menuItemId' => $item->id,
            'slug' => $item->slug,
            'name' => $item->name,
            'description' => $item->description,
            'price' => $this->rm($item->price_cents),
            'promoPrice' => $item->promotional_price_cents === null ? null : $this->rm($item->promotional_price_cents),
            'image' => $item->image,
            'category' => $item->category?->name ?? 'Menu',
            'popular' => $item->popular,
            'available' => $item->available,
            'modifierGroups' => $item->relationLoaded('modifierGroups') ? $item->modifierGroups->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'required' => $group->required,
                'minimumSelections' => $group->minimum_selections,
                'maximumSelections' => $group->maximum_selections,
                'options' => $group->options->map(fn ($option) => ['id' => $option->id, 'name' => $option->name, 'priceAdjustment' => $this->rm($option->price_adjustment_cents)]),
            ])->values() : [],
        ];
    }

    private function rm(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
