<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * Liste des boutiques.
     */
    public function index(Request $request)
    {
        $stores = Store::query()
            // Boutiques des vendeurs dont l'abonnement est actif uniquement.
            ->whereHas('user.subscriptions', function ($q) {
                $q->where('status', 'actif')->where('ends_at', '>', now());
            })
            ->withCount(['products' => function ($q) {
                $q->where('is_active', true);
            }])->orderBy('name')->paginate(12);

        return response()->json($stores);
    }

    /**
     * Une boutique + ses produits actifs.
     */
    public function show(Store $store)
    {
        abort_unless(
            $store->user->subscriptions()
                ->where('status', 'actif')
                ->where('ends_at', '>', now())
                ->exists(),
            404
        );

        $store->loadCount(['products' => function ($q) {
            $q->where('is_active', true);
        }]);

        $products = $store->products()
            ->where('is_active', true)
            ->with('category:id,name,slug')
            ->orderByDesc('id')
            ->paginate(12);

        return response()->json(['store' => $store, 'products' => $products]);
    }
}
