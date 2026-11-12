<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Liste des catégories + nombre de produits actifs.
     */
    public function index(Request $request)
    {
        $categories = Category::withCount(['products' => function ($q) {
            $q->where('is_active', true);
        }])->orderBy('name')->get();

        return response()->json($categories);
    }
}
