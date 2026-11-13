<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Espace vendeur : boutique, produits, commandes reçues.
 * Toutes les routes exigent un compte vendeur (auth:sanctum).
 */
class VendorController extends Controller
{
    public function __construct(protected \App\Services\Notifier $notifier) {}

    public function myStore(Request $request)
    {
        $user = $this->vendor($request);

        return response()->json([
            'store' => $user->store()->withCount('products')->first(),
        ]);
    }

    /**
     * Créer ou mettre à jour sa boutique.
     */
    public function saveStore(Request $request)
    {
        $user  = $this->vendor($request);
        $store = $user->store;

        $data = $request->validate([
            'name'        => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'city'        => ['nullable', 'string', 'max:80'],
            'phone'       => ['nullable', 'string', 'max:30'],
        ]);

        if ($store) {
            $store->update($data);
        } else {
            $data['user_id'] = $user->id;
            $data['slug']    = $this->uniqueSlug(Store::class, $data['name']);
            $store = Store::create($data);
        }

        return response()->json($store->loadCount('products'));
    }

    /**
     * Mes produits (y compris inactifs).
     */
    public function products(Request $request)
    {
        $store = $this->requireStore($request);

        $products = $store->products()
            ->with(['category:id,name,slug', 'images'])
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json($products);
    }

    public function storeProduct(Request $request)
    {
        $store = $this->requireStore($request);
        $data  = $this->validatedProduct($request);
        $images = $data['images'] ?? [];
        unset($data['images']);

        $data['store_id'] = $store->id;
        $data['slug']     = $this->uniqueSlug(Product::class, $data['name']);

        $product = Product::create($data);
        $this->syncImages($product, $images);

        return response()->json($product->load('images'), 201);
    }

    public function updateProduct(Request $request, Product $product)
    {
        $store   = $this->requireStore($request);
        $product = $store->products()->findOrFail($product->id);

        $data   = $this->validatedProduct($request);
        $images = $data['images'] ?? null;
        unset($data['images']);

        $product->update($data);

        if ($images !== null) {
            $this->syncImages($product, $images);
        }

        return response()->json($product->load('images'));
    }

    public function deleteProduct(Request $request, Product $product)
    {
        $store   = $this->requireStore($request);
        $product = $store->products()->findOrFail($product->id);
        $product->delete();

        return response()->json(['message' => 'Produit supprimé.']);
    }

    /**
     * Commandes contenant au moins un de mes produits
     * (avec uniquement mes lignes).
     */
    public function orders(Request $request)
    {
        $store = $this->requireStore($request);

        $orders = Order::query()
            ->whereHas('items', function ($q) use ($store) {
                $q->where('store_id', $store->id);
            })
            ->with([
                'user:id,name,phone',
                'courier:id,name,phone',
                'reports' => fn ($q) => $q->latest(),
                'items' => function ($q) use ($store) {
                    $q->where('store_id', $store->id);
                },
            ])
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json($orders);
    }

    /**
     * Changer le statut d'une commande reçue.
     * body : { status: confirmee | livree | annulee }
     */
    public function updateOrderStatus(Request $request, Order $order)
    {
        $store = $this->requireStore($request);

        $data = $request->validate([
            'status' => ['required', 'in:confirmee,livree,annulee'],
        ]);

        $order = Order::whereHas('items', function ($q) use ($store) {
            $q->where('store_id', $store->id);
        })->findOrFail($order->id);

        $ancien = $order->status;

        $attrs = ['status' => $data['status']];
        if ($data['status'] === 'confirmee' && ! $order->confirmed_at) {
            $attrs['confirmed_at'] = now();
        }
        if ($data['status'] === 'livree' && ! $order->delivered_at) {
            $attrs['delivered_at'] = now();
        }

        $order->update($attrs);

        if ($data['status'] === 'confirmee' && $ancien !== 'confirmee') {
            $this->notifier->to(
                $order->user,
                'order_confirmed',
                'Commande confirmée ✅',
                "Le vendeur a confirmé votre commande {$order->reference} (".number_format($order->total, 0, ',', ' ')." FCFA). Payez par Orange Money puis déclarez le paiement dans l'application.",
                $order,
                ['order_id' => $order->id]
            );

            // Les livreurs de CETTE boutique sont prévenus (pas ceux des autres).
            $couriers = $this->notifier->couriersOfStore($store->id);

            $this->notifier->toMany(
                $couriers,
                'delivery_request',
                'Nouvelle livraison disponible 🛵',
                "Commande {$order->reference} confirmée à {$order->address}. Le vendeur peut vous l'attribuer.",
                $order,
                ['order_id' => $order->id]
            );

            // Aucun livreur dans la boutique : le vendeur et le responsable sont prévenus.
            if ($couriers->isEmpty()) {
                $this->notifier->to(
                    $request->user(),
                    'delivery_request',
                    'Aucun livreur dans votre boutique ⚠️',
                    "La commande {$order->reference} est confirmée. Créez un livreur dans « 🛵 Livreurs » puis attribuez-lui la course.",
                    $order,
                    ['order_id' => $order->id]
                );

                $this->notifier->toMany(
                    $this->notifier->admins(),
                    'delivery_request',
                    'Aucun livreur disponible ⚠️',
                    "La commande {$order->reference} est confirmée mais la boutique {$store->name} n'a aucun livreur.",
                    $order,
                    ['order_id' => $order->id]
                );
            }
        }

        if ($data['status'] === 'annulee' && $ancien !== 'annulee') {
            // Les produits retournent dans le stock de la boutique.
            $order->restoreStock();

            $this->notifier->to(
                $order->user,
                'order_cancelled',
                'Commande annulée',
                "La commande {$order->reference} a été annulée par le vendeur.",
                $order
            );
        }

        return response()->json(
            $order->load([
                'user:id,name,phone',
                'items' => function ($q) use ($store) {
                    $q->where('store_id', $store->id);
                },
            ])
        );
    }

    /**
     * Le vendeur a retrouvé l'argent Orange Money : il confirme le paiement.
     */
    public function confirmPayment(Request $request, Order $order)
    {
        $store = $this->requireStore($request);

        $order = Order::whereHas('items', function ($q) use ($store) {
            $q->where('store_id', $store->id);
        })->findOrFail($order->id);

        abort_if($order->status === 'annulee', 422, 'Cette commande est annulée.');

        $order->update([
            'payment_status' => 'paye',
            'paid_at'        => now(),
        ]);

        $this->notifier->to(
            $order->user,
            'payment_confirmed',
            'Paiement reçu 💰',
            "Votre paiement pour la commande {$order->reference} est confirmé. La livraison peut commencer.",
            $order,
            ['order_id' => $order->id]
        );

        $this->notifier->toMany(
            $order->courier_id
                ? \App\Models\User::whereKey($order->courier_id)->get()
                : $this->notifier->couriersOfStore($store->id),
            'delivery_request',
            'Commande payée, à livrer 🛵',
            "Commande {$order->reference} — ".number_format($order->total, 0, ',', ' ')." FCFA — à retirer chez {$store->name}, livraison à {$order->address}.",
            $order,
            ['order_id' => $order->id]
        );

        return response()->json(['order' => $order->fresh(['user:id,name,phone', 'items'])]);
    }

    /* ------------------------------------------------------------ livreurs */

    /**
     * Tableau de bord de la boutique : ventes, commandes à traiter,
     * courses, stock faible, top produits et 7 derniers jours.
     */
    public function dashboard(Request $request)
    {
        $store = $this->requireStore($request);

        $paid    = ['confirmee', 'livree'];
        $today   = now()->startOfDay();
        $myItems = fn () => OrderItem::where('store_id', $store->id);
        $ordersOf = fn () => Order::whereHas('items', fn ($q) => $q->where('store_id', $store->id));

        $salesToday = (int) $myItems()
            ->whereHas('order', fn ($q) => $q->whereIn('status', $paid)->where('created_at', '>=', $today))
            ->sum('subtotal');

        $salesTotal = (int) $myItems()
            ->whereHas('order', fn ($q) => $q->whereIn('status', $paid))
            ->sum('subtotal');

        // 7 derniers jours (du plus ancien au plus récent).
        $jours = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
        $serie = [];
        for ($i = 6; $i >= 0; $i--) {
            $start = now()->subDays($i)->startOfDay();
            $end   = (clone $start)->endOfDay();

            $serie[] = [
                'label' => $jours[$start->dayOfWeek],
                'date'  => $start->toDateString(),
                'total' => (int) $myItems()
                    ->whereHas('order', fn ($q) => $q->whereIn('status', $paid)
                        ->whereBetween('created_at', [$start, $end]))
                    ->sum('subtotal'),
            ];
        }

        $topProduits = $myItems()
            ->whereHas('order', fn ($q) => $q->whereIn('status', $paid))
            ->selectRaw('product_name, sum(qty) as quantite, sum(subtotal) as total')
            ->groupBy('product_name')
            ->orderByDesc('quantite')
            ->limit(5)
            ->get();

        $produits = Product::where('store_id', $store->id)->get(['id', 'name', 'stock', 'is_active']);

        return response()->json([
            'store' => $store->only(['id', 'name', 'description', 'city', 'phone']),
            'stats' => [
                'sales_today'   => $salesToday,
                'sales_total'   => $salesTotal,
                'orders_today'  => $ordersOf()->where('created_at', '>=', $today)->count(),
                'to_confirm'    => $ordersOf()->where('status', 'en_attente')->count(),
                'to_assign'     => $ordersOf()->where('status', 'confirmee')->whereNull('courier_id')->count(),
                'in_delivery'   => $ordersOf()->where('status', 'en_livraison')->count(),
                'delivered'     => $ordersOf()->where('status', 'livree')->count(),
                'products'      => $produits->count(),
                'products_hidden' => $produits->where('is_active', false)->count(),
                'low_stock'     => $produits->where('stock', '<=', 3)->count(),
            ],
            'serie'         => $serie,
            'top_produits'  => $topProduits,
            'stock_faible'  => $produits->where('stock', '<=', 3)->sortBy('stock')->take(5)->values(),
            'couriers'      => [
                'total'    => User::where('role', 'livreur')->where('store_id', $store->id)->count(),
                'en_cours' => Order::whereIn(
                    'courier_id',
                    User::where('role', 'livreur')->where('store_id', $store->id)->select('id')
                )->where('status', 'en_livraison')->count(),
            ],
            'subscription'  => $request->user()->latestSubscription,
            'recentes'      => $ordersOf()
                ->with(['user:id,name', 'items' => fn ($q) => $q->where('store_id', $store->id)])
                ->orderByDesc('id')->limit(5)->get(['id', 'user_id', 'reference', 'status', 'total', 'created_at']),
        ]);
    }

    /** Les livreurs de ma boutique, avec le nombre de courses en cours. */
    public function couriers(Request $request)
    {
        $store = $this->requireStore($request);

        $couriers = User::where('role', 'livreur')
            ->where('store_id', $store->id)
            ->withCount([
                'deliveries as en_cours' => fn ($q) => $q->where('status', 'en_livraison'),
                'deliveries as livrees'  => fn ($q) => $q->where('status', 'livree'),
            ])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $couriers]);
    }

    /**
     * Créer un livreur pour SA boutique.
     * body : { name, email, password, phone }
     */
    public function createCourier(Request $request)
    {
        $store = $this->requireStore($request);
        $this->vendor($request);

        $data = $request->validate([
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'email'    => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'phone'    => ['required', 'string', 'min:6', 'max:30'],
        ], [
            'email.unique'   => 'Cette adresse e-mail est déjà utilisée.',
            'password.min'   => 'Le mot de passe doit faire au moins 8 caractères.',
            'phone.required' => 'Le téléphone est indispensable : le livreur reçoit les courses par SMS.',
        ]);

        abort_if(
            User::where('role', 'livreur')->where('store_id', $store->id)->count() >= 20,
            422,
            'Vous avez atteint la limite de 20 livreurs pour cette boutique.'
        );

        $courier = User::create([
            'name'     => $data['name'],
            'email'    => mb_strtolower($data['email']),
            'password' => $data['password'],
            'phone'    => $data['phone'],
            'role'     => 'livreur',
            'store_id' => $store->id,
        ]);

        return response()->json(['courier' => $courier], 201);
    }

    /**
     * Modifier un de mes livreurs (nom, téléphone, mot de passe).
     */
    public function updateCourier(Request $request, User $courier)
    {
        $store = $this->requireStore($request);
        $this->assertMyCourier($store, $courier);

        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'min:2', 'max:100'],
            'phone'    => ['sometimes', 'string', 'min:6', 'max:30'],
            'password' => ['sometimes', 'string', 'min:8', 'max:72'],
        ], [
            'password.min' => 'Le mot de passe doit faire au moins 8 caractères.',
        ]);

        $courier->update($data);

        if (isset($data['password'])) {
            $courier->tokens()->delete();   // déconnecte les anciens appareils
        }

        return response()->json(['courier' => $courier->fresh()]);
    }

    /**
     * Attribuer une commande à un de mes livreurs (il reçoit un SMS).
     * body : { courier_id }
     */
    public function assignCourier(Request $request, Order $order)
    {
        $store = $this->requireStore($request);
        $this->vendor($request);

        $data = $request->validate([
            'courier_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $courier = User::where('role', 'livreur')->findOrFail($data['courier_id']);
        $this->assertMyCourier($store, $courier);

        abort_if($courier->estBloque(), 422, 'Ce livreur est bloqué.');

        $order = Order::whereHas('items', function ($q) use ($store) {
            $q->where('store_id', $store->id);
        })->findOrFail($order->id);

        abort_if(
            in_array($order->status, ['annulee', 'livree'], true),
            422,
            'Cette commande est terminée ou annulée.'
        );

        abort_if(
            $order->courier_id && (int) $order->courier_id !== (int) $courier->id,
            409,
            'Cette commande est déjà attribuée à un autre livreur.'
        );

        $order->update(['courier_id' => $courier->id]);

        $this->notifier->to(
            $courier,
            'delivery_request',
            'Course attribuée 🛵',
            "La boutique {$store->name} vous confie la commande {$order->reference} à {$order->address}. Ouvrez votre espace Livraisons.",
            $order,
            ['order_id' => $order->id]
        );

        return response()->json([
            'order'   => $order->fresh(['courier:id,name,phone', 'user:id,name,phone']),
            'message' => "Commande attribuée à {$courier->name}. Il est prévenu par SMS.",
        ]);
    }

    /** Suivi : les livraisons d'un livreur de ma boutique. */
    public function courierDeliveries(Request $request, User $courier)
    {
        $store = $this->requireStore($request);
        $this->assertMyCourier($store, $courier);

        $orders = Order::where('courier_id', $courier->id)
            ->with([
                'user:id,name,phone',
                'items' => fn ($q) => $q->where('store_id', $store->id),
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'courier' => $courier->only(['id', 'name', 'phone', 'email', 'blocked_at']),
            'data'    => $orders,
        ]);
    }

    /** Vérifie que ce livreur appartient bien à la boutique du vendeur. */
    private function assertMyCourier(Store $store, User $courier): void
    {
        abort_unless(
            $courier->role === 'livreur' && (int) $courier->store_id === (int) $store->id,
            403,
            "Ce livreur n'appartient pas à votre boutique."
        );
    }

    /**
     * Envoyer la photo d'un produit (vendeur).
     * body multipart : image=<fichier jpg/png/webp>
     * Réponse : { path: "/media/{id}" }
     *
     * La photo est enregistrée DANS LA BASE : elle survit aux redémarrages
     * et aux redéploiements (disque éphémère sur Render).
     */
    public function uploadImage(Request $request)
    {
        $store = $this->requireStore($request);

        $data = $request->validate([
            'image' => [
                'required', 'file',
                'mimes:jpg,jpeg,png,webp',
                'max:4096',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ], [
            'image.mimes'      => 'Formats acceptés : JPG, PNG ou WEBP.',
            'image.max'        => 'Photo trop lourde (4 Mo maximum).',
            'image.dimensions' => 'Photo trop grande (6000 pixels maximum).',
        ]);

        $file  = $data['image'];
        $media = Media::create([
            'mime' => $file->getMimeType() ?: 'image/jpeg',
            'name' => mb_substr($file->getClientOriginalName(), 0, 120),
            'data' => base64_encode(file_get_contents($file->getRealPath())),
        ]);

        return response()->json(['path' => '/media/' . $media->id, 'id' => $media->id], 201);
    }

    // ---------------------------------------------------------------- helpers

    private function vendor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user->role === 'vendeur', 403, 'Réservé aux vendeurs.');

        return $user;
    }

    private function requireStore(Request $request): Store
    {
        $user  = $this->vendor($request);
        $store = $user->store()->first();

        abort_if($store === null, 422, "Créez d'abord votre boutique.");

        return $store;
    }

    private function validatedProduct(Request $request): array
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price'       => ['required', 'integer', 'min:0'],
            'stock'       => ['required', 'integer', 'min:0', 'max:100000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'image'       => ['nullable', 'string', 'max:255'],
            'images'      => ['nullable', 'array', 'max:8'],
            'images.*'    => ['string', 'max:255'],
            'colors'      => ['nullable', 'array', 'max:12'],
            'colors.*'    => ['string', 'max:30'],
            'sizes'       => ['nullable', 'array', 'max:15'],
            'sizes.*'     => ['string', 'max:20'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        // Nettoyage des variantes : valeurs vides retirées, doublons supprimés.
        foreach (['colors', 'sizes'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = array_values(array_unique(array_filter(
                    array_map(fn ($v) => trim((string) $v), $data[$key] ?? []),
                    fn ($v) => $v !== ''
                )));
            }
        }

        return $data;
    }

    /**
     * Enregistre la galerie du produit et garde la première photo
     * comme photo principale (utilisée dans les listes).
     */
    private function syncImages(Product $product, array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));

        $product->images()->whereNotIn('path', $paths ?: [''])->delete();

        foreach ($paths as $position => $path) {
            $product->images()->updateOrCreate(
                ['path' => $path],
                ['position' => $position]
            );
        }

        $product->update([
            'image' => $paths[0] ?? null,
        ]);
    }

    private function uniqueSlug(string $model, string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'article';
        $slug = $base;
        $i    = 1;

        while ($model::where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$i;
        }

        return $slug;
    }
}
