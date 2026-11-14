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
            // Modèle commission : toutes les boutiques sont visibles,
            // sauf celles d'un vendeur bloqué.
            ->whereHas('user', fn ($q) => $q->whereNull('blocked_at'))
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
        abort_if($store->user?->blocked_at !== null, 404);

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
