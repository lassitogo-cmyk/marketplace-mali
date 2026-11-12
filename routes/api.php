<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Middleware\EnsureNotBlocked;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Marketplace Mali — consommée par l'application Flutter
|--------------------------------------------------------------------------
| Préfixe automatique : /api  (ex. http://mac:8000/api/products)
*/

// ---------- Public ----------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/stores', [StoreController::class, 'index']);
Route::get('/stores/{store}', [StoreController::class, 'show']);

// ---------- Connecté (client ou vendeur) ----------
Route::middleware(['auth:sanctum', EnsureNotBlocked::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Client : commandes + signalement d'un problème
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/report', [OrderController::class, 'report']);

    // Vendeur : boutique, produits, commandes reçues
    Route::get('/vendor/store', [VendorController::class, 'myStore']);
    Route::post('/vendor/store', [VendorController::class, 'saveStore']);
    Route::get('/vendor/products', [VendorController::class, 'products']);
    Route::post('/vendor/products', [VendorController::class, 'storeProduct']);
    Route::put('/vendor/products/{product}', [VendorController::class, 'updateProduct']);
    Route::delete('/vendor/products/{product}', [VendorController::class, 'deleteProduct']);
    Route::post('/vendor/image', [VendorController::class, 'uploadImage']);
    Route::get('/vendor/orders', [VendorController::class, 'orders']);
    Route::put('/vendor/orders/{order}/status', [VendorController::class, 'updateOrderStatus']);

    // Vendeur : son abonnement (10 000 FCFA / 30 jours)
    Route::get('/subscriptions/mine', [SubscriptionController::class, 'mine']);
    Route::post('/subscriptions/request', [SubscriptionController::class, 'demander']);
    Route::post('/subscriptions/pay', [SubscriptionController::class, 'declarerPaiement']);

    // Responsable du marché : personnes + comptes vendeurs
    Route::prefix('admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/vendor', [AdminController::class, 'createVendor']);
        Route::put('/users/{user}/role', [AdminController::class, 'updateRole']);
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);
        Route::put('/profile', [AdminController::class, 'updateProfile']);

        // Contrôle du marché : tout voir, intervenir, bloquer
        Route::get('/orders', [AdminController::class, 'orders']);
        Route::put('/orders/{order}/status', [AdminController::class, 'updateOrderStatus']);
        Route::get('/products', [AdminController::class, 'products']);
        Route::put('/products/{product}/visibility', [AdminController::class, 'setProductVisibility']);
        Route::delete('/products/{product}', [AdminController::class, 'deleteProduct']);
        Route::post('/users/{user}/block', [AdminController::class, 'blockUser']);
        Route::post('/users/{user}/unblock', [AdminController::class, 'unblockUser']);
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::put('/reports/{report}/resolve', [AdminController::class, 'resolveReport']);

        // Abonnements vendeurs : activation, désactivation, suivi
        Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
        Route::get('/subscriptions/{subscription}', [AdminController::class, 'showSubscription']);
        Route::post('/subscriptions/{subscription}/activate', [AdminController::class, 'activateSubscription']);
        Route::post('/subscriptions/{subscription}/deactivate', [AdminController::class, 'deactivateSubscription']);
        Route::post('/subscriptions/{subscription}/reject-payment', [AdminController::class, 'rejectPayment']);

        // Réglages du marché (numéro Orange Money affiché aux vendeurs)
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::put('/settings', [AdminController::class, 'saveSettings']);
    });
});
