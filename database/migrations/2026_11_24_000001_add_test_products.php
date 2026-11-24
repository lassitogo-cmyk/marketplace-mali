<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Quelques produits clairement identifiés « TEST » pour vérifier l'affichage
 * en ligne (grandes cartes, recherche, connexion puis vitrine, panier).
 * Idempotent : relancer le déploiement ne crée pas de doublons.
 * Pour retirer : php artisan migrate:rollback --step=1 (ou supprimer les
 * lignes dont le nom commence par « TEST – »).
 */
return new class extends Migration
{
    private const PREFIX = 'TEST – ';

    public function up(): void
    {
        if (DB::table('products')->where('name', 'like', self::PREFIX.'%')->exists()) {
            return;
        }

        $storeId = DB::table('stores')->where('name', 'Sora chop')->value('id')
            ?: DB::table('stores')->orderBy('id')->value('id');
        if (! $storeId) {
            return;
        }

        $cat = fn (string $name) => DB::table('categories')->where('name', $name)->value('id');
        $photo = DB::table('media')->where('id', 14)->exists() ? '/media/14' : null;

        $list = [
            ['Pagne wax 6 yards', 'Mode', 15000, 12],
            ['Écouteurs Bluetooth', 'Électronique', 18500, 20],
            ['Sneakers cuir', 'Mode', 24500, 8],
            ['Natte teintée', 'Maison', 12000, 15],
            ['Tablette 10 pouces', 'Électronique', 190000, 3],
        ];

        foreach ($list as $i => [$name, $category, $price, $stock]) {
            $id = DB::table('products')->insertGetId([
                'store_id'    => $storeId,
                'category_id' => $cat($category),
                'name'        => self::PREFIX.$name,
                'slug'        => Str::slug(self::PREFIX.$name).'-test-'.($i + 1),
                'description' => 'Produit de test — à retirer après validation de l\'affichage.',
                'price'       => $price,
                'stock'       => $stock,
                'image'       => $photo,
                'is_active'   => true,
                'colors'      => json_encode(['Rouge', 'Vert']),
                'sizes'       => json_encode(['M', 'L']),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            if ($photo && $i < 2) {
                DB::table('product_images')->insert([
                    'product_id' => $id,
                    'path'       => $photo,
                    'position'   => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('products')->where('name', 'like', self::PREFIX.'%')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('product_images')->whereIn('product_id', $ids)->delete();
        DB::table('products')->whereIn('id', $ids)->delete();
    }
};
