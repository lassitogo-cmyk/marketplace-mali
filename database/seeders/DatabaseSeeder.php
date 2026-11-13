<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Jeu de données de démonstration de la Marketplace Mali.
     * Le mot de passe des comptes de démo n'est jamais écrit dans le code :
     * il vient de DEMO_PASSWORD, sinon un mot de passe aléatoire est généré
     * et affiché une seule fois à l'écran.
     */
    public function run(): void
    {
        $demoPassword = env('DEMO_PASSWORD') ?: Str::password(16);

        if (! env('DEMO_PASSWORD')) {
            $this->command?->warn("Mot de passe des comptes de démonstration : {$demoPassword}");
        }

        User::create([
            'name'     => 'Aminata Diallo',
            'email'    => 'client@demo.mali',
            'password' => $demoPassword,
            'phone'    => '+223 76 00 00 01',
            'role'     => 'client',
        ]);

        $fatou = User::create([
            'name'     => 'Fatoumata Traoré',
            'email'    => 'fatou@demo.mali',
            'password' => $demoPassword,
            'phone'    => '+223 76 00 00 02',
            'role'     => 'vendeur',
        ]);

        $moussa = User::create([
            'name'     => 'Moussa Keïta',
            'email'    => 'moussa@demo.mali',
            'password' => $demoPassword,
            'phone'    => '+223 76 00 00 03',
            'role'     => 'vendeur',
        ]);

        $electronique = Category::create(['name' => 'Électronique', 'slug' => 'electronique']);
        $mode          = Category::create(['name' => 'Mode', 'slug' => 'mode']);
        $maison        = Category::create(['name' => 'Maison', 'slug' => 'maison']);

        $sabali = Store::create([
            'user_id'     => $fatou->id,
            'name'        => 'Boutique Sabali',
            'slug'        => 'boutique-sabali',
            'description' => 'Smartphones, tablettes et accessoires à prix Bamako.',
            'city'        => 'Bamako',
            'phone'       => '+223 76 00 00 02',
        ]);

        $wax = Store::create([
            'user_id'     => $moussa->id,
            'name'        => 'Wax & Pagne',
            'slug'        => 'wax-pagne',
            'description' => 'Tissus wax, pagnes et vêtements cousus sur mesure.',
            'city'        => 'Bamako',
            'phone'       => '+223 76 00 00 03',
        ]);

        // [boutique, catégorie, nom, prix FCFA, stock, description]
        $products = [
            [$sabali->id, $electronique->id, 'Smartphone Galaxy A15', 145000, 12, 'Écran 6,5", batterie 5000 mAh, double SIM.'],
            [$sabali->id, $electronique->id, 'Écouteurs Bluetooth TWS', 18500, 40, 'Réduction de bruit, autonomie 24 h.'],
            [$sabali->id, $electronique->id, 'Powerbank 20000 mAh', 22000, 25, 'Charge rapide, 2 ports USB.'],
            [$sabali->id, $electronique->id, 'Tablette 10 pouces', 190000, 5, 'Wi-Fi, 128 Go, étui inclus.'],
            [$sabali->id, $electronique->id, 'Montre connectée', 35000, 15, 'Suivi du sommeil et du rythme cardiaque.'],
            [$wax->id, $mode->id, 'Pagne wax 6 yards', 15000, 60, 'Coton authentique, plusieurs motifs disponibles.'],
            [$wax->id, $mode->id, 'Robe bazin brodée', 45000, 10, 'Bazin riche, broderie faite main.'],
            [$wax->id, $mode->id, 'Chemise homme wax', 18000, 30, 'Coupe moderne, tissu wax.'],
            [$wax->id, $maison->id, 'Natte teintée', 12000, 20, 'Natte en raphia teintée à la main.'],
            [$wax->id, $maison->id, 'Panier tressé', 8000, 35, 'Panier artisanal en bois local.'],
        ];

        foreach ($products as [$storeId, $categoryId, $name, $price, $stock, $description]) {
            Product::create([
                'store_id'    => $storeId,
                'category_id' => $categoryId,
                'name'        => $name,
                'slug'        => Str::slug($name) . '-' . Str::lower(Str::random(4)),
                'description' => $description,
                'price'       => $price,
                'stock'       => $stock,
                'is_active'   => true,
            ]);
        }
    }
};
