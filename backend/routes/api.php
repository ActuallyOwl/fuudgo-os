<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/restaurants', [CatalogController::class, 'restaurants']);
Route::get('/restaurants/{slug}', [CatalogController::class, 'restaurant']);
Route::get('/promotions', [CatalogController::class, 'promotions']);
Route::get('/locations', fn () => response()->json(['data' => ['Kuching City Centre', 'Tabuan Jaya', 'Pending', 'Satok', 'Petra Jaya', 'Batu Kawa', 'Matang', 'Stutong']]));

Route::middleware('throttle:auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/admin/login', [AuthController::class, 'adminLogin']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('customer')->group(function (): void {
        Route::patch('/account/profile', [AccountController::class, 'updateProfile']);
        Route::get('/account/addresses', [AccountController::class, 'addresses']);
        Route::post('/account/addresses', [AccountController::class, 'addAddress']);
        Route::put('/account/addresses/{addressId}', [AccountController::class, 'updateAddress']);
        Route::delete('/account/addresses/{addressId}', [AccountController::class, 'deleteAddress']);
        Route::get('/account/favourites', [AccountController::class, 'favourites']);
        Route::post('/account/favourites/{restaurantId}', [AccountController::class, 'toggleFavourite']);
        Route::get('/account/orders', [AccountController::class, 'orders']);
        Route::get('/account/orders/{reference}', [CheckoutController::class, 'show']);
        Route::post('/orders', [CheckoutController::class, 'place'])->middleware('throttle:orders');
        Route::get('/orders/{reference}', [CheckoutController::class, 'show']);
    });

    Route::prefix('admin')->middleware('admin')->group(function (): void {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/restaurants', [AdminController::class, 'restaurants']);
        Route::post('/restaurants', [AdminController::class, 'createRestaurant']);
        Route::put('/restaurants/{restaurantId}', [AdminController::class, 'updateRestaurant']);
        Route::patch('/restaurants/{restaurantId}/status', [AdminController::class, 'restaurantStatus']);
        Route::delete('/restaurants/{restaurantId}', [AdminController::class, 'archiveRestaurant']);
        Route::get('/restaurants/{restaurantId}/categories', [AdminController::class, 'categories']);
        Route::post('/restaurants/{restaurantId}/categories', [AdminController::class, 'createCategory']);
        Route::put('/categories/{categoryId}', [AdminController::class, 'updateCategory']);
        Route::delete('/categories/{categoryId}', [AdminController::class, 'deleteCategory']);
        Route::get('/restaurants/{restaurantId}/items', [AdminController::class, 'items']);
        Route::post('/restaurants/{restaurantId}/items', [AdminController::class, 'createItem']);
        Route::put('/items/{itemId}', [AdminController::class, 'updateItem']);
        Route::patch('/items/{itemId}/availability', [AdminController::class, 'itemAvailability']);
        Route::post('/items/{itemId}/duplicate', [AdminController::class, 'duplicateItem']);
        Route::delete('/items/{itemId}', [AdminController::class, 'deleteItem']);
        Route::post('/media', [AdminController::class, 'uploadImage']);
        Route::get('/orders', [AdminController::class, 'orders']);
        Route::get('/orders/{reference}', [AdminController::class, 'orderDetail']);
        Route::patch('/orders/{reference}/status', [AdminController::class, 'updateOrderStatus']);
        Route::get('/customers', [AdminController::class, 'customers']);
        Route::get('/promotions', [AdminController::class, 'promotions']);
        Route::post('/promotions', [AdminController::class, 'createPromotion']);
        Route::put('/promotions/{promotionId}', [AdminController::class, 'updatePromotion']);
        Route::delete('/promotions/{promotionId}', [AdminController::class, 'deletePromotion']);
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::patch('/settings', [AdminController::class, 'updateSettings']);
    });
});
