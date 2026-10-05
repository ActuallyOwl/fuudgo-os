<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'current_password' => ['required_with:password', 'string'],
            'password' => ['nullable', 'confirmed', 'string', 'min:12'],
        ]);

        if (isset($data['password']) && ! Hash::check($data['current_password'] ?? '', $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password did not match.']);
        }

        if ($data['email'] !== $user->email) {
            $data['email_verified_at'] = null;
        }
        unset($data['current_password'], $data['password_confirmation']);
        $user->fill($data);
        if (isset($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $user->role]]);
    }

    public function addresses(Request $request)
    {
        return response()->json(['data' => $request->user()->addresses()->orderByDesc('is_default')->orderBy('id')->get()]);
    }

    public function addAddress(Request $request)
    {
        $data = $this->addressData($request);
        $address = $request->user()->addresses()->create($data);
        if ($data['is_default']) {
            $request->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        }
        return response()->json(['data' => $address], 201);
    }

    public function updateAddress(Request $request, int $addressId)
    {
        $address = $request->user()->addresses()->findOrFail($addressId);
        $data = $this->addressData($request);
        $address->update($data);
        if ($data['is_default']) {
            $request->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        }
        return response()->json(['data' => $address->refresh()]);
    }

    public function deleteAddress(Request $request, int $addressId)
    {
        $request->user()->addresses()->findOrFail($addressId)->delete();
        return response()->json(['message' => 'Address removed.']);
    }

    public function favourites(Request $request)
    {
        $favourites = $request->user()->favourites()->where('status', 'active')->orderBy('name')->get();
        return response()->json(['data' => $favourites->map(fn (Restaurant $restaurant) => [
            'id' => (string) $restaurant->id,
            'slug' => $restaurant->slug,
            'name' => $restaurant->name,
            'image' => $restaurant->cover_image,
            'cuisine' => $restaurant->cuisines,
            'rating' => (float) $restaurant->rating,
            'area' => $restaurant->area,
        ])]);
    }

    public function toggleFavourite(Request $request, int $restaurantId)
    {
        $restaurant = Restaurant::query()->whereKey($restaurantId)->where('status', 'active')->firstOrFail();
        $attached = $request->user()->favourites()->toggle([$restaurant->id]);
        return response()->json(['saved' => in_array($restaurant->id, $attached['attached'], true)]);
    }

    public function orders(Request $request)
    {
        $orders = Order::query()->with('restaurant')->where('user_id', $request->user()->id)->latest('placed_at')->paginate(20);
        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order) => [
                'reference' => $order->reference,
                'restaurant' => $order->restaurant->name,
                'restaurantSlug' => $order->restaurant->slug,
                'fulfilment' => $order->fulfilment,
                'status' => $order->status,
                'paymentStatus' => $order->payment_status,
                'total' => round($order->total_cents / 100, 2),
                'placedAt' => $order->placed_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ]);
    }

    private function addressData(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'min:8', 'max:32'],
            'line_1' => ['required', 'string', 'max:180'],
            'line_2' => ['nullable', 'string', 'max:180'],
            'area' => ['required', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:12'],
            'city' => ['sometimes', 'string', 'max:100'],
            'state' => ['sometimes', 'string', 'max:100'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ]) + ['is_default' => $request->boolean('is_default')];
    }
}
