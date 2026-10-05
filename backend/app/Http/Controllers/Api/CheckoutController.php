<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Restaurant;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function place(Request $request)
    {
        $data = $request->validate([
            'fulfilment' => ['required', 'in:delivery,pickup'],
            'payment_method' => ['required', 'in:cash_on_delivery,cash_on_pickup,online'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.menu_item_id' => ['required', 'integer', 'distinct', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.modifiers' => ['sometimes', 'array', 'max:10'],
            'items.*.modifiers.*.group_id' => ['required', 'integer'],
            'items.*.modifiers.*.option_ids' => ['sometimes', 'array', 'max:12'],
            'items.*.modifiers.*.option_ids.*' => ['integer', 'distinct'],
            'delivery_address' => ['required_if:fulfilment,delivery', 'array'],
            'delivery_address.line_1' => ['required_if:fulfilment,delivery', 'string', 'min:4', 'max:180'],
            'delivery_address.line_2' => ['nullable', 'string', 'max:180'],
            'delivery_address.area' => ['required_if:fulfilment,delivery', 'string', 'max:100'],
            'delivery_address.postcode' => ['nullable', 'string', 'max:12'],
            'delivery_address.delivery_instructions' => ['nullable', 'string', 'max:500'],
            'customer_phone' => ['required', 'string', 'min:8', 'max:32'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'promo_code' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $cashEnabled = $this->setting('cod_enabled', true);
        if (in_array($data['payment_method'], ['cash_on_delivery', 'cash_on_pickup'], true) && ! $cashEnabled) {
            throw ValidationException::withMessages(['payment_method' => 'Cash payment is currently unavailable.']);
        }
        if ($data['payment_method'] === 'cash_on_delivery' && $data['fulfilment'] !== 'delivery') {
            throw ValidationException::withMessages(['payment_method' => 'Choose cash on pickup for a pickup order.']);
        }
        if ($data['payment_method'] === 'cash_on_pickup' && $data['fulfilment'] !== 'pickup') {
            throw ValidationException::withMessages(['payment_method' => 'Choose cash on delivery for a delivery order.']);
        }
        if ($data['payment_method'] === 'online') {
            throw ValidationException::withMessages(['payment_method' => 'Online payments are not configured yet. Choose cash on delivery or pickup.']);
        }
        if (! $this->setting('ordering_enabled', true)) {
            throw ValidationException::withMessages(['order' => 'Ordering is temporarily paused. Please try again later.']);
        }

        $order = DB::transaction(function () use ($data, $user): Order {
            $requestedIds = collect($data['items'])->pluck('menu_item_id')->all();
            /** @var Collection<int, MenuItem> $items */
            $items = MenuItem::query()
                ->whereIn('id', $requestedIds)
                ->where('available', true)
                ->whereHas('restaurant', fn ($query) => $query->where('status', 'active'))
                ->with(['restaurant', 'modifierGroups' => fn ($query) => $query->where('active', true), 'modifierGroups.options' => fn ($query) => $query->where('available', true)])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($items->count() !== count($requestedIds)) {
                throw ValidationException::withMessages(['items' => 'A menu item is no longer available. Refresh the menu and try again.']);
            }

            $restaurantIds = $items->pluck('restaurant_id')->unique();
            if ($restaurantIds->count() !== 1) {
                throw ValidationException::withMessages(['items' => 'Your basket can include items from one restaurant at a time.']);
            }

            /** @var Restaurant $restaurant */
            $restaurant = Restaurant::query()->whereKey($restaurantIds->first())->where('status', 'active')->lockForUpdate()->firstOrFail();
            $lineSnapshots = [];
            $subtotal = 0;

            foreach ($data['items'] as $requestedLine) {
                /** @var MenuItem $item */
                $item = $items->get((int) $requestedLine['menu_item_id']);
                $quantity = (int) $requestedLine['quantity'];
                $basePrice = $item->promotional_price_cents ?? $item->price_cents;
                $modifierSnapshots = [];
                $modifierTotal = 0;
                $providedGroups = collect($requestedLine['modifiers'] ?? []);

                if ($providedGroups->pluck('group_id')->unique()->count() !== $providedGroups->count()) {
                    throw ValidationException::withMessages(['items' => 'A menu customization was selected more than once.']);
                }

                foreach ($item->modifierGroups as $group) {
                    $selection = $providedGroups->firstWhere('group_id', $group->id);
                    $optionIds = collect($selection['option_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
                    $min = $group->required ? max(1, $group->minimum_selections) : $group->minimum_selections;
                    if ($optionIds->count() < $min || $optionIds->count() > $group->maximum_selections) {
                        throw ValidationException::withMessages(['items' => "Choose between {$min} and {$group->maximum_selections} options for {$group->name}."]);
                    }

                    $options = $group->options->whereIn('id', $optionIds)->values();
                    if ($options->count() !== $optionIds->count()) {
                        throw ValidationException::withMessages(['items' => 'A selected menu extra is no longer available.']);
                    }

                    foreach ($options as $option) {
                        $modifierTotal += $option->price_adjustment_cents;
                        $modifierSnapshots[] = ['group' => $group->name, 'name' => $option->name, 'price_cents' => $option->price_adjustment_cents];
                    }
                    $providedGroups = $providedGroups->reject(fn ($entry) => (int) $entry['group_id'] === $group->id)->values();
                }

                if ($providedGroups->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => 'A menu extra does not belong to this dish. Refresh the menu and try again.']);
                }

                $unitPrice = $basePrice + $modifierTotal;
                $lineTotal = $unitPrice * $quantity;
                $subtotal += $lineTotal;
                $lineSnapshots[] = ['item' => $item, 'quantity' => $quantity, 'base_price' => $basePrice, 'unit_price' => $unitPrice, 'line_total' => $lineTotal, 'modifiers' => $modifierSnapshots];
            }

            if ($subtotal < $restaurant->minimum_order_cents) {
                $minimum = number_format($restaurant->minimum_order_cents / 100, 2);
                throw ValidationException::withMessages(['items' => "This restaurant has a minimum order of RM {$minimum}."]);
            }

            $deliveryFee = $data['fulfilment'] === 'delivery'
                && ($restaurant->free_delivery_threshold_cents === null || $subtotal < $restaurant->free_delivery_threshold_cents)
                ? $restaurant->delivery_fee_cents
                : 0;
            $discount = 0;
            $promotion = null;

            if (! empty($data['promo_code'])) {
                $promotion = Promotion::query()->whereRaw('UPPER(code) = ?', [Str::upper($data['promo_code'])])->lockForUpdate()->first();
                if (! $promotion || ! $promotion->active || ($promotion->starts_at && $promotion->starts_at->isFuture()) || ($promotion->ends_at && $promotion->ends_at->isPast())) {
                    throw ValidationException::withMessages(['promo_code' => 'That deal is unavailable or has expired.']);
                }
                if ($promotion->restaurant_id && $promotion->restaurant_id !== $restaurant->id) {
                    throw ValidationException::withMessages(['promo_code' => 'That deal is for another restaurant.']);
                }
                if ($subtotal < $promotion->minimum_spend_cents) {
                    throw ValidationException::withMessages(['promo_code' => 'Add a little more to your basket to use that deal.']);
                }
                if ($promotion->usage_limit !== null && $promotion->redeemed_count >= $promotion->usage_limit) {
                    throw ValidationException::withMessages(['promo_code' => 'This deal has reached its usage limit.']);
                }
                if (PromotionUsage::query()->where('promotion_id', $promotion->id)->where('user_id', $user->id)->count() >= $promotion->uses_per_customer) {
                    throw ValidationException::withMessages(['promo_code' => 'You have already used this deal the maximum number of times.']);
                }

                $discount = match ($promotion->type) {
                    'percentage' => min((int) round($subtotal * $promotion->value / 100), $promotion->maximum_discount_cents ?? $subtotal, $subtotal),
                    'fixed' => min($promotion->value, $promotion->maximum_discount_cents ?? $promotion->value, $subtotal),
                    'free_delivery' => $deliveryFee,
                    default => throw ValidationException::withMessages(['promo_code' => 'This deal type is not supported.']),
                };
                // Keep the original fee in the breakdown and offset it with the
                // promotion discount. This avoids subtracting the same fee twice.
            }

            $address = $data['fulfilment'] === 'delivery' ? [
                'line_1' => $data['delivery_address']['line_1'],
                'line_2' => $data['delivery_address']['line_2'] ?? null,
                'area' => $data['delivery_address']['area'],
                'postcode' => $data['delivery_address']['postcode'] ?? null,
                'city' => 'Kuching',
                'state' => 'Sarawak',
                'delivery_instructions' => $data['delivery_address']['delivery_instructions'] ?? null,
            ] : null;
            $order = Order::create([
                'reference' => 'FUUD-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'user_id' => $user->id,
                'restaurant_id' => $restaurant->id,
                'promotion_id' => $promotion?->id,
                'fulfilment' => $data['fulfilment'],
                'status' => 'pending',
                'payment_method' => $data['payment_method'],
                'payment_status' => 'pending',
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => $data['customer_phone'],
                'delivery_address' => $address,
                'customer_note' => $data['customer_note'] ?? null,
                'subtotal_cents' => $subtotal,
                'delivery_fee_cents' => $deliveryFee,
                'discount_cents' => $discount,
                'total_cents' => max(0, $subtotal + $deliveryFee - $discount),
                'placed_at' => now(),
            ]);

            foreach ($lineSnapshots as $line) {
                $order->items()->create([
                    'menu_item_id' => $line['item']->id,
                    'item_name' => $line['item']->name,
                    'base_unit_price_cents' => $line['base_price'],
                    'unit_price_cents' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total_cents' => $line['line_total'],
                    'modifiers_snapshot' => $line['modifiers'],
                ]);
            }

            Payment::create(['order_id' => $order->id, 'provider' => 'cash', 'status' => 'pending', 'amount_cents' => $order->total_cents]);
            OrderStatusHistory::create(['order_id' => $order->id, 'changed_by' => $user->id, 'from_status' => null, 'to_status' => 'pending', 'note' => 'Order placed.']);

            if ($promotion) {
                PromotionUsage::create(['promotion_id' => $promotion->id, 'user_id' => $user->id, 'order_id' => $order->id, 'discount_cents' => $discount]);
                $promotion->increment('redeemed_count');
            }

            return $order->load(['restaurant', 'items', 'histories', 'payment']);
        }, 3);

        return response()->json(['data' => $this->formatOrder($order)], 201);
    }

    public function show(Request $request, string $reference)
    {
        $order = Order::query()->with(['restaurant', 'items', 'histories', 'payment'])->where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();
        return response()->json(['data' => $this->formatOrder($order)]);
    }

    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->reference,
            'reference' => $order->reference,
            'restaurant' => ['id' => (string) $order->restaurant->id, 'name' => $order->restaurant->name, 'slug' => $order->restaurant->slug],
            'fulfilment' => $order->fulfilment,
            'status' => $order->status,
            'paymentMethod' => $order->payment_method,
            'paymentStatus' => $order->payment_status,
            'customerName' => $order->customer_name,
            'customerPhone' => $order->customer_phone,
            'deliveryAddress' => $order->delivery_address,
            'customerNote' => $order->customer_note,
            'subtotal' => round($order->subtotal_cents / 100, 2),
            'deliveryFee' => round($order->delivery_fee_cents / 100, 2),
            'discount' => round($order->discount_cents / 100, 2),
            'total' => round($order->total_cents / 100, 2),
            'placedAt' => $order->placed_at->toIso8601String(),
            'items' => $order->items->map(fn ($item) => ['name' => $item->item_name, 'quantity' => $item->quantity, 'unitPrice' => round($item->unit_price_cents / 100, 2), 'lineTotal' => round($item->line_total_cents / 100, 2), 'modifiers' => $item->modifiers_snapshot ?? []]),
            'history' => $order->histories->map(fn ($entry) => ['status' => $entry->to_status, 'note' => $entry->note, 'at' => $entry->created_at->toIso8601String()]),
        ];
    }

    private function setting(string $key, bool $default): bool
    {
        return (bool) data_get(Setting::query()->find($key)?->value, 'value', $default);
    }
}
