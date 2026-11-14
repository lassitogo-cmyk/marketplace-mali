<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Catalogue public : ?search=&category=&store=&page=
     */
    public function index(Request $request)
    {
        $query = Product::query()
            ->where('is_active', true)
            // Modèle commission : toutes les boutiques sont visibles,
            // sauf celles d'un vendeur bloqué.
            ->whereHas('store.user', fn ($q) => $q->whereNull('blocked_at'))
            ->with(['store:id,name,slug', 'category:id,name,slug', 'images'])
            ->orderByDesc('id');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $categoryId = (int) $request->query('category', 0);
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        $storeId = (int) $request->query('store', 0);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }

        return response()->json($query->paginate(12));
    }

    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);
        abort_if($product->store->user?->blocked_at !== null, 404);

        $product->load(['store:id,name,slug,description,city,phone', 'category:id,name,slug', 'images']);

        return response()->json($product);
    }
}
