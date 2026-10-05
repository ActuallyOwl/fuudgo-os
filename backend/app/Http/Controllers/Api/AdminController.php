<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Promotion;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function dashboard()
    {
        $today = Order::query()->whereDate('placed_at', today());
        return response()->json(['data' => [
            'ordersToday' => (clone $today)->count(),
            'revenueToday' => round((clone $today)->where('payment_status', 'paid')->sum('total_cents') / 100, 2),
            'pendingOrders' => Order::whereIn('status', ['pending', 'confirmed'])->count(),
            'preparingOrders' => Order::where('status', 'preparing')->count(),
            'completedOrders' => Order::whereIn('status', ['delivered', 'picked_up'])->count(),
            'cancelledOrders' => Order::where('status', 'cancelled')->count(),
            'registeredCustomers' => User::where('role', 'customer')->count(),
            'activeRestaurants' => Restaurant::where('status', 'active')->count(),
            'averageOrderValue' => round((float) Order::where('payment_status', 'paid')->avg(DB::raw('total_cents / 100')), 2),
            'recentOrders' => Order::with('restaurant')->latest('placed_at')->limit(10)->get()->map(fn (Order $order) => $this->orderSummary($order)),
            'dailyRevenue' => collect(range(6, 0))->map(function (int $daysAgo): array {
                $day = today()->subDays($daysAgo);
                return ['date' => $day->toDateString(), 'revenue' => round(Order::whereDate('placed_at', $day)->where('payment_status', 'paid')->sum('total_cents') / 100, 2)];
            }),
        ]]);
    }

    public function restaurants(Request $request)
    {
        $query = Restaurant::withTrashed()->withCount('menuItems')->orderBy('name');
        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->query('q')).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('area', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        return response()->json(['data' => $query->paginate(30)->through(fn (Restaurant $r) => $this->restaurantSummary($r))]);
    }

    public function createRestaurant(Request $request)
    {
        $data = $this->restaurantData($request);
        $data['slug'] = $this->uniqueRestaurantSlug($data['slug'] ?? $data['name']);
        if ($request->hasFile('cover')) {
            $data['cover_image'] = $this->storeImage($request, 'cover', 'restaurants');
        }
        if ($request->hasFile('logo')) {
            $data['logo_image'] = $this->storeImage($request, 'logo', 'restaurants/logos');
        }
        $restaurant = Restaurant::create($data);
        return response()->json(['data' => $this->restaurantSummary($restaurant)], 201);
    }

    public function updateRestaurant(Request $request, int $restaurantId)
    {
        $restaurant = Restaurant::withTrashed()->findOrFail($restaurantId);
        $data = $this->restaurantData($request, $restaurant);
        if (isset($data['slug']) && $data['slug'] !== $restaurant->slug) {
            $data['slug'] = $this->uniqueRestaurantSlug($data['slug'], $restaurant->id);
        }
        if ($request->hasFile('cover')) {
            $data['cover_image'] = $this->storeImage($request, 'cover', 'restaurants');
        }
        if ($request->hasFile('logo')) {
            $data['logo_image'] = $this->storeImage($request, 'logo', 'restaurants/logos');
        }
        $restaurant->update($data);
        if ($restaurant->trashed()) {
            $restaurant->restore();
        }
        return response()->json(['data' => $this->restaurantSummary($restaurant->refresh())]);
    }

    public function restaurantStatus(Request $request, int $restaurantId)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $restaurant = Restaurant::withTrashed()->findOrFail($restaurantId);
        $restaurant->update($data);
        if ($data['status'] === 'active' && $restaurant->trashed()) {
            $restaurant->restore();
        }
        return response()->json(['data' => $this->restaurantSummary($restaurant->refresh())]);
    }

    public function archiveRestaurant(int $restaurantId)
    {
        $restaurant = Restaurant::findOrFail($restaurantId);
        $restaurant->update(['status' => 'inactive']);
        $restaurant->delete();
        return response()->json(['message' => 'Restaurant archived.']);
    }

    public function categories(int $restaurantId)
    {
        Restaurant::findOrFail($restaurantId);
        return response()->json(['data' => MenuCategory::where('restaurant_id', $restaurantId)->withCount('items')->orderBy('sort_order')->get()]);
    }

    public function createCategory(Request $request, int $restaurantId)
    {
        Restaurant::where('status', 'active')->findOrFail($restaurantId);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'], 'active' => ['sometimes', 'boolean']]);
        $category = MenuCategory::create($data + ['restaurant_id' => $restaurantId]);
        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(Request $request, int $categoryId)
    {
        $category = MenuCategory::findOrFail($categoryId);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'], 'active' => ['sometimes', 'boolean']]);
        $category->update($data);
        return response()->json(['data' => $category]);
    }

    public function deleteCategory(int $categoryId)
    {
        MenuCategory::findOrFail($categoryId)->delete();
        return response()->json(['message' => 'Menu category removed. Items remain in the restaurant menu without a category.']);
    }

    public function items(int $restaurantId)
    {
        return response()->json(['data' => MenuItem::with(['category', 'modifierGroups.options'])->where('restaurant_id', $restaurantId)->orderBy('sort_order')->paginate(50)->through(fn (MenuItem $item) => $this->itemSummary($item))]);
    }

    public function createItem(Request $request, int $restaurantId)
    {
        Restaurant::findOrFail($restaurantId);
        $data = $this->itemData($request, $restaurantId);
        $modifierGroups = $data['modifier_groups'] ?? [];
        unset($data['modifier_groups'], $data['image_file']);
        $item = DB::transaction(function () use ($request, $data, $modifierGroups, $restaurantId): MenuItem {
            $item = MenuItem::create($data + ['restaurant_id' => $restaurantId, 'slug' => $this->uniqueItemSlug($restaurantId, $data['slug'] ?? $data['name'])]);
            if ($request->hasFile('image_file')) {
                $item->update(['image' => $this->storeImage($request, 'image_file', 'menu-items')]);
            }
            $this->syncModifiers($item, $modifierGroups);
            return $item->load(['category', 'modifierGroups.options']);
        });
        return response()->json(['data' => $this->itemSummary($item)], 201);
    }

    public function updateItem(Request $request, int $itemId)
    {
        $item = MenuItem::findOrFail($itemId);
        $data = $this->itemData($request, $item->restaurant_id, $item);
        $replaceModifiers = array_key_exists('modifier_groups', $data);
        $modifierGroups = $data['modifier_groups'] ?? [];
        unset($data['modifier_groups'], $data['image_file']);
        $item = DB::transaction(function () use ($request, $data, $replaceModifiers, $modifierGroups, $item): MenuItem {
            $item->update($data);
            if ($request->hasFile('image_file')) {
                $item->update(['image' => $this->storeImage($request, 'image_file', 'menu-items')]);
            }
            if ($replaceModifiers) {
                $this->syncModifiers($item, $modifierGroups);
            }
            return $item->load(['category', 'modifierGroups.options']);
        });
        return response()->json(['data' => $this->itemSummary($item)]);
    }

    public function itemAvailability(Request $request, int $itemId)
    {
        $data = $request->validate(['available' => ['required', 'boolean']]);
        $item = MenuItem::findOrFail($itemId);
        $item->update($data);
        return response()->json(['data' => $this->itemSummary($item->load(['category', 'modifierGroups.options']))]);
    }

    public function duplicateItem(int $itemId)
    {
        $item = MenuItem::with(['modifierGroups.options'])->findOrFail($itemId);
        return DB::transaction(function () use ($item) {
            $copy = $item->replicate(['slug']);
            $copy->name = $item->name.' copy';
            $copy->slug = $this->uniqueItemSlug($item->restaurant_id, $copy->name);
            $copy->available = false;
            $copy->save();
            foreach ($item->modifierGroups as $group) {
                $copyGroup = $group->replicate(['menu_item_id']);
                $copyGroup->menu_item_id = $copy->id;
                $copyGroup->save();
                foreach ($group->options as $option) {
                    $copyGroup->options()->create($option->only(['name', 'price_adjustment_cents', 'available', 'sort_order']));
                }
            }
            return response()->json(['data' => $this->itemSummary($copy->load(['category', 'modifierGroups.options']))], 201);
        });
    }

    public function deleteItem(int $itemId)
    {
        MenuItem::findOrFail($itemId)->delete();
        return response()->json(['message' => 'Menu item archived.']);
    }

    public function orders(Request $request)
    {
        $query = Order::with(['restaurant', 'user', 'payment'])->latest('placed_at');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->query('q')).'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('customer_name', 'like', $term)->orWhere('customer_phone', 'like', $term)->orWhereHas('restaurant', fn ($restaurant) => $restaurant->where('name', 'like', $term)));
        }
        return response()->json(['data' => $query->paginate(30)->through(fn (Order $order) => $this->orderSummary($order))]);
    }

    public function orderDetail(string $reference)
    {
        $order = Order::with(['restaurant', 'user', 'items', 'histories', 'payment'])->where('reference', $reference)->firstOrFail();
        return response()->json(['data' => $this->orderDetailPayload($order)]);
    }

    public function updateOrderStatus(Request $request, string $reference)
    {
        $data = $request->validate(['status' => ['required', 'string', Rule::in(['confirmed', 'preparing', 'ready_for_pickup', 'rider_assigned', 'picked_up', 'on_the_way', 'delivered', 'cancelled'])], 'note' => ['nullable', 'string', 'max:500'], 'confirm_cancel' => ['sometimes', 'boolean']]);

        $order = DB::transaction(function () use ($request, $data, $reference): Order {
            $order = Order::where('reference', $reference)->lockForUpdate()->firstOrFail();
            $allowed = [
                'pending' => ['confirmed', 'cancelled'],
                'confirmed' => ['preparing', 'cancelled'],
                'preparing' => $order->fulfilment === 'pickup' ? ['ready_for_pickup', 'cancelled'] : ['rider_assigned', 'cancelled'],
                'ready_for_pickup' => ['picked_up'],
                'rider_assigned' => ['picked_up'],
                'picked_up' => $order->fulfilment === 'delivery' ? ['on_the_way'] : [],
                'on_the_way' => ['delivered'],
                'delivered' => [],
                'cancelled' => [],
            ];
            if (! in_array($data['status'], $allowed[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'That status change is not allowed for this order.']);
            }
            if ($data['status'] === 'cancelled' && ! ($data['confirm_cancel'] ?? false)) {
                throw ValidationException::withMessages(['confirm_cancel' => 'Confirm that you want to cancel this order.']);
            }

            $previous = $order->status;
            $order->status = $data['status'];
            if (in_array($data['status'], ['delivered', 'picked_up'], true)) {
                $order->completed_at = now();
                $order->payment_status = 'paid';
                $order->payment?->update(['status' => 'paid', 'paid_at' => now()]);
            }
            if ($data['status'] === 'cancelled') {
                $order->payment_status = 'cancelled';
                $order->payment?->update(['status' => 'cancelled']);
            }
            $order->save();
            OrderStatusHistory::create(['order_id' => $order->id, 'changed_by' => $request->user()->id, 'from_status' => $previous, 'to_status' => $order->status, 'note' => $data['note'] ?? null]);
            return $order->load(['restaurant', 'user', 'items', 'histories', 'payment']);
        });

        return response()->json(['data' => $this->orderDetailPayload($order)]);
    }

    public function customers(Request $request)
    {
        $query = User::query()->where('role', 'customer')->withCount('orders')->withSum('orders', 'total_cents')->orderByDesc('created_at');
        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->query('q')).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
        }
        return response()->json(['data' => $query->paginate(30)->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'registeredAt' => $user->created_at->toIso8601String(),
            'orderCount' => $user->orders_count,
            'totalSpend' => round(($user->orders_sum_total_cents ?? 0) / 100, 2),
        ])]);
    }

    public function promotions()
    {
        return response()->json(['data' => Promotion::with('restaurant')->orderByDesc('created_at')->paginate(30)->through(fn (Promotion $promotion) => $this->promotionSummary($promotion))]);
    }

    public function createPromotion(Request $request)
    {
        $data = $this->promotionData($request);
        $promotion = Promotion::create($data);
        return response()->json(['data' => $this->promotionSummary($promotion)], 201);
    }

    public function updatePromotion(Request $request, int $promotionId)
    {
        $promotion = Promotion::findOrFail($promotionId);
        $data = $this->promotionData($request, $promotion);
        $promotion->update($data);
        return response()->json(['data' => $this->promotionSummary($promotion->refresh()->load('restaurant'))]);
    }

    public function deletePromotion(int $promotionId)
    {
        $promotion = Promotion::findOrFail($promotionId);
        if ($promotion->redeemed_count > 0) {
            $promotion->update(['active' => false]);
            return response()->json(['message' => 'Promotion deactivated because it has been used.']);
        }
        $promotion->delete();
        return response()->json(['message' => 'Promotion removed.']);
    }

    public function settings()
    {
        $defaults = ['business_name' => 'FuudGo', 'support_email' => null, 'default_currency' => 'MYR', 'default_delivery_fee' => 3.00, 'ordering_enabled' => true, 'cod_enabled' => true, 'online_payments_enabled' => false, 'maintenance_message' => null];
        foreach (Setting::all() as $setting) {
            if (array_key_exists($setting->key, $defaults)) {
                $value = data_get($setting->value, 'value');
                $defaults[$setting->key] = $setting->key === 'default_delivery_fee' ? round(((int) $value) / 100, 2) : $value;
            }
        }
        return response()->json(['data' => $defaults]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'default_currency' => ['sometimes', Rule::in(['MYR'])],
            'default_delivery_fee' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'ordering_enabled' => ['sometimes', 'boolean'],
            'cod_enabled' => ['sometimes', 'boolean'],
            'maintenance_message' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if ($request->boolean('online_payments_enabled')) {
            throw ValidationException::withMessages(['online_payments_enabled' => 'Online payments remain disabled until a payment provider is configured.']);
        }
        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => ['value' => $key === 'default_delivery_fee' ? (int) round((float) $value * 100) : $value]]);
        }
        return $this->settings();
    }

    public function uploadImage(Request $request)
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $file = $request->file('image');
        $path = $file->storePublicly('uploads', 'public');
        $media = Media::create(['disk' => 'public', 'path' => $path, 'original_name' => Str::limit($file->getClientOriginalName(), 240, ''), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
        return response()->json(['data' => ['id' => $media->id, 'url' => Storage::disk('public')->url($path), 'mimeType' => $media->mime_type, 'sizeBytes' => $media->size_bytes]], 201);
    }

    private function restaurantData(Request $request, ?Restaurant $restaurant = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('restaurants', 'slug')->ignore($restaurant?->id)],
            'description' => ['nullable', 'string', 'max:1500'],
            'cuisines' => ['required', 'array', 'min:1', 'max:12'],
            'cuisines.*' => ['string', 'max:50'],
            'tags' => ['sometimes', 'array', 'max:12'],
            'tags.*' => ['string', 'max:80'],
            'promotion_badge' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:254'],
            'area' => ['required', 'string', 'max:100'],
            'operating_hours' => ['nullable', 'array'],
            'delivery_minutes' => ['required', 'integer', 'min:1', 'max:240'],
            'pickup_minutes' => ['required', 'integer', 'min:1', 'max:240'],
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:250'],
            'minimum_order' => ['required', 'numeric', 'min:0', 'max:1000'],
            'free_delivery_threshold' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'rating' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'review_count' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'featured' => ['sometimes', 'boolean'],
            'cover_image_url' => ['nullable', 'url', 'max:2048'],
            'logo_image_url' => ['nullable', 'url', 'max:2048'],
        ]);
        $data['delivery_fee_cents'] = (int) round((float) $data['delivery_fee'] * 100);
        $data['minimum_order_cents'] = (int) round((float) $data['minimum_order'] * 100);
        $data['free_delivery_threshold_cents'] = isset($data['free_delivery_threshold']) ? (int) round((float) $data['free_delivery_threshold'] * 100) : null;
        $data['slug'] = isset($data['slug']) && $data['slug'] !== '' ? $data['slug'] : Str::slug($data['name']);
        $data['cover_image'] = $data['cover_image_url'] ?? $restaurant?->cover_image;
        $data['logo_image'] = $data['logo_image_url'] ?? $restaurant?->logo_image;
        unset($data['delivery_fee'], $data['minimum_order'], $data['free_delivery_threshold'], $data['cover_image_url'], $data['logo_image_url']);
        return $data + ['tags' => $restaurant?->tags ?? [], 'status' => $restaurant?->status ?? 'active', 'featured' => $restaurant?->featured ?? false];
    }

    private function itemData(Request $request, int $restaurantId, ?MenuItem $item = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:1500'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:10000'],
            'promotional_price' => ['nullable', 'numeric', 'min:0.01', 'max:10000'],
            'menu_category_id' => ['nullable', 'integer', 'exists:menu_categories,id'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image_file' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'dietary_tags' => ['sometimes', 'array', 'max:12'],
            'dietary_tags.*' => ['string', 'max:40'],
            'available' => ['sometimes', 'boolean'],
            'popular' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'modifier_groups' => ['sometimes', 'array', 'max:12'],
            'modifier_groups.*.name' => ['required', 'string', 'max:100'],
            'modifier_groups.*.required' => ['sometimes', 'boolean'],
            'modifier_groups.*.minimum_selections' => ['sometimes', 'integer', 'min:0', 'max:12'],
            'modifier_groups.*.maximum_selections' => ['required', 'integer', 'min:1', 'max:12'],
            'modifier_groups.*.options' => ['required', 'array', 'min:1', 'max:30'],
            'modifier_groups.*.options.*.name' => ['required', 'string', 'max:100'],
            'modifier_groups.*.options.*.price_adjustment' => ['required', 'numeric', 'min:0', 'max:10000'],
            'modifier_groups.*.options.*.available' => ['sometimes', 'boolean'],
        ]);
        if (! empty($data['menu_category_id']) && ! MenuCategory::where('restaurant_id', $restaurantId)->whereKey($data['menu_category_id'])->exists()) {
            throw ValidationException::withMessages(['menu_category_id' => 'Choose a category from this restaurant.']);
        }
        $data['price_cents'] = (int) round((float) $data['price'] * 100);
        $data['promotional_price_cents'] = isset($data['promotional_price']) ? (int) round((float) $data['promotional_price'] * 100) : null;
        if ($data['promotional_price_cents'] !== null && $data['promotional_price_cents'] >= $data['price_cents']) {
            throw ValidationException::withMessages(['promotional_price' => 'Promotional price must be lower than the regular price.']);
        }
        $data['slug'] = isset($data['slug']) && $data['slug'] !== '' ? $data['slug'] : Str::slug($data['name']);
        if (! $item || $data['slug'] !== $item->slug) {
            $data['slug'] = $this->uniqueItemSlug($restaurantId, $data['slug'], $item?->id);
        }
        $data['image'] = $data['image_url'] ?? $item?->image;
        unset($data['price'], $data['promotional_price'], $data['image_url']);
        return $data + ['available' => $item?->available ?? true, 'popular' => $item?->popular ?? false, 'featured' => $item?->featured ?? false, 'sort_order' => $item?->sort_order ?? 0];
    }

    private function syncModifiers(MenuItem $item, array $groups): void
    {
        $item->modifierGroups()->delete();
        foreach ($groups as $groupIndex => $groupData) {
            $minimum = (int) ($groupData['minimum_selections'] ?? (($groupData['required'] ?? false) ? 1 : 0));
            $maximum = (int) ($groupData['maximum_selections'] ?? 1);
            if ($minimum > $maximum) {
                throw ValidationException::withMessages(['modifier_groups' => 'The minimum modifier selections cannot exceed the maximum.']);
            }
            $group = $item->modifierGroups()->create([
                'name' => $groupData['name'],
                'required' => (bool) ($groupData['required'] ?? false),
                'minimum_selections' => $minimum,
                'maximum_selections' => $maximum,
                'sort_order' => $groupIndex,
                'active' => true,
            ]);
            foreach ($groupData['options'] as $optionIndex => $optionData) {
                $group->options()->create([
                    'name' => $optionData['name'],
                    'price_adjustment_cents' => (int) round((float) $optionData['price_adjustment'] * 100),
                    'available' => (bool) ($optionData['available'] ?? true),
                    'sort_order' => $optionIndex,
                ]);
            }
        }
    }

    private function promotionData(Request $request, ?Promotion $promotion = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('promotions', 'code')->ignore($promotion?->id)],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['percentage', 'fixed', 'free_delivery'])],
            'value' => ['required_if:type,percentage,fixed', 'numeric', 'min:0', 'max:100000'],
            'minimum_spend' => ['required', 'numeric', 'min:0', 'max:10000'],
            'maximum_discount' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'uses_per_customer' => ['required', 'integer', 'min:1', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if ($data['type'] === 'percentage' && ((float) $data['value'] < 1 || (float) $data['value'] > 100)) {
            throw ValidationException::withMessages(['value' => 'Percentage discounts must be between 1 and 100.']);
        }
        $data['code'] = Str::upper($data['code']);
        $data['value'] = $data['type'] === 'fixed' ? (int) round((float) $data['value'] * 100) : (int) $data['value'];
        $data['minimum_spend_cents'] = (int) round((float) $data['minimum_spend'] * 100);
        $data['maximum_discount_cents'] = isset($data['maximum_discount']) ? (int) round((float) $data['maximum_discount'] * 100) : null;
        unset($data['minimum_spend'], $data['maximum_discount']);
        return $data + ['active' => true];
    }

    private function storeImage(Request $request, string $field, string $folder): string
    {
        $request->validate([$field => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $file = $request->file($field);
        $path = $file->storePublicly($folder, 'public');
        Media::create(['disk' => 'public', 'path' => $path, 'original_name' => Str::limit($file->getClientOriginalName(), 240, ''), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
        return Storage::disk('public')->url($path);
    }

    private function uniqueRestaurantSlug(string $value, ?int $exceptId = null): string
    {
        $base = Str::slug($value) ?: 'restaurant';
        $slug = $base;
        $i = 2;
        while (Restaurant::withTrashed()->where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $slug = $base.'-'.$i++;
        }
        return $slug;
    }

    private function uniqueItemSlug(int $restaurantId, string $value, ?int $exceptId = null): string
    {
        $base = Str::slug($value) ?: 'menu-item';
        $slug = $base;
        $i = 2;
        while (MenuItem::withTrashed()->where('restaurant_id', $restaurantId)->where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $slug = $base.'-'.$i++;
        }
        return $slug;
    }

    private function restaurantSummary(Restaurant $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug, 'description' => $r->description, 'cuisines' => $r->cuisines, 'tags' => $r->tags, 'coverImage' => $r->cover_image, 'logoImage' => $r->logo_image, 'area' => $r->area, 'deliveryMinutes' => $r->delivery_minutes, 'pickupMinutes' => $r->pickup_minutes, 'deliveryFee' => round($r->delivery_fee_cents / 100, 2), 'minimumOrder' => round($r->minimum_order_cents / 100, 2), 'freeDeliveryThreshold' => $r->free_delivery_threshold_cents === null ? null : round($r->free_delivery_threshold_cents / 100, 2), 'rating' => (float) $r->rating, 'reviewCount' => $r->review_count, 'status' => $r->status, 'featured' => $r->featured, 'menuItemsCount' => $r->menu_items_count ?? null, 'archived' => $r->trashed()];
    }

    private function itemSummary(MenuItem $item): array
    {
        return ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug, 'description' => $item->description, 'category' => $item->category?->name, 'categoryId' => $item->menu_category_id, 'price' => round($item->price_cents / 100, 2), 'promotionalPrice' => $item->promotional_price_cents === null ? null : round($item->promotional_price_cents / 100, 2), 'image' => $item->image, 'dietaryTags' => $item->dietary_tags ?? [], 'available' => $item->available, 'popular' => $item->popular, 'featured' => $item->featured, 'sortOrder' => $item->sort_order, 'modifierGroups' => $item->modifierGroups->map(fn ($group) => ['id' => $group->id, 'name' => $group->name, 'required' => $group->required, 'minimumSelections' => $group->minimum_selections, 'maximumSelections' => $group->maximum_selections, 'options' => $group->options->map(fn ($option) => ['id' => $option->id, 'name' => $option->name, 'priceAdjustment' => round($option->price_adjustment_cents / 100, 2), 'available' => $option->available])])];
    }

    private function orderSummary(Order $order): array
    {
        return ['reference' => $order->reference, 'customerName' => $order->customer_name, 'customerPhone' => $order->customer_phone, 'restaurant' => $order->restaurant->name, 'fulfilment' => $order->fulfilment, 'status' => $order->status, 'paymentMethod' => $order->payment_method, 'paymentStatus' => $order->payment_status, 'total' => round($order->total_cents / 100, 2), 'placedAt' => $order->placed_at->toIso8601String()];
    }

    private function orderDetailPayload(Order $order): array
    {
        return $this->orderSummary($order) + [
            'restaurantId' => $order->restaurant_id,
            'customerEmail' => $order->customer_email,
            'deliveryAddress' => $order->delivery_address,
            'customerNote' => $order->customer_note,
            'subtotal' => round($order->subtotal_cents / 100, 2),
            'deliveryFee' => round($order->delivery_fee_cents / 100, 2),
            'discount' => round($order->discount_cents / 100, 2),
            'items' => $order->items->map(fn ($item) => ['name' => $item->item_name, 'quantity' => $item->quantity, 'unitPrice' => round($item->unit_price_cents / 100, 2), 'lineTotal' => round($item->line_total_cents / 100, 2), 'modifiers' => $item->modifiers_snapshot ?? []]),
            'history' => $order->histories->map(fn ($entry) => ['from' => $entry->from_status, 'to' => $entry->to_status, 'note' => $entry->note, 'at' => $entry->created_at->toIso8601String()]),
        ];
    }

    private function promotionSummary(Promotion $promotion): array
    {
        return ['id' => $promotion->id, 'code' => $promotion->code, 'title' => $promotion->title, 'description' => $promotion->description, 'type' => $promotion->type, 'value' => $promotion->type === 'fixed' ? round($promotion->value / 100, 2) : $promotion->value, 'minimumSpend' => round($promotion->minimum_spend_cents / 100, 2), 'maximumDiscount' => $promotion->maximum_discount_cents === null ? null : round($promotion->maximum_discount_cents / 100, 2), 'restaurantId' => $promotion->restaurant_id, 'restaurant' => $promotion->restaurant?->name, 'startsAt' => $promotion->starts_at?->toIso8601String(), 'endsAt' => $promotion->ends_at?->toIso8601String(), 'usageLimit' => $promotion->usage_limit, 'usesPerCustomer' => $promotion->uses_per_customer, 'redeemedCount' => $promotion->redeemed_count, 'active' => $promotion->active];
    }
}
