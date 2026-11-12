<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Order;
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

            // Le livreur reçoit la demande dès la confirmation du vendeur.
            $this->notifier->toMany(
                $this->notifier->couriers(),
                'delivery_request',
                'Nouvelle livraison disponible 🛵',
                "Commande {$order->reference} confirmée à {$order->address}. Prête à être prise en charge.",
                $order,
                ['order_id' => $order->id]
            );
        }

        if ($data['status'] === 'annulee' && $ancien !== 'annulee') {
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
            $this->notifier->couriers(),
            'delivery_request',
            'Commande payée, à livrer 🛵',
            "Commande {$order->reference} — ".number_format($order->total, 0, ',', ' ')." FCFA — à retirer chez {$store->name}, livraison à {$order->address}.",
            $order,
            ['order_id' => $order->id]
        );

        return response()->json(['order' => $order->fresh(['user:id,name,phone', 'items'])]);
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
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        $file  = $data['image'];
        $media = Media::create([
            'mime' => $file->getMimeType() ?: 'image/jpeg',
            'name' => $file->getClientOriginalName(),
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
