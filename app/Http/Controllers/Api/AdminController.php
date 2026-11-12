<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Interface du responsable du marché (rôle « admin ») :
 * voir toutes les personnes inscrites, créer les comptes
 * vendeurs / vendeuses, changer les rôles, supprimer un compte.
 */
class AdminController extends Controller
{
    public function __construct(protected \App\Services\Notifier $notifier) {}

    /**
     * Statistiques générales du marché.
     */
    public function stats(Request $request)
    {
        $this->admin($request);

        return response()->json([
            'users'         => User::count(),
            'clients'       => User::where('role', 'client')->count(),
            'vendors'       => User::where('role', 'vendeur')->count(),
            'stores'        => Store::count(),
            'products'      => Product::count(),
            'orders'        => Order::count(),
            'revenue'       => (int) Order::whereIn('status', ['confirmee', 'livree'])->sum('total'),
            'subs_pending'  => Subscription::where('status', 'en_attente')->count(),
            'subs_to_verify'=> Subscription::where('status', 'paiement_declare')->count(),
            'subs_active'   => Subscription::where('status', 'actif')->where('ends_at', '>', now())->count(),
            'subs_revenue'  => (int) Subscription::whereNotNull('activated_at')->sum('amount'),
            'reports_new'   => Report::where('status', 'nouveau')->count(),
            'blocked'       => User::whereNotNull('blocked_at')->count(),
        ]);
    }

    /**
     * Toutes les personnes inscrites (recherche + pagination).
     */
    public function users(Request $request)
    {
        $this->admin($request);

        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->with('store:id,name')
            ->with('latestSubscription')
            ->withCount('orders')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Créer un compte vendeur / vendeuse.
     * body : { name, email, password, phone?, store_name? }
     */
    public function createVendor(Request $request)
    {
        $admin = $this->admin($request);

        $data = $request->validate([
            'name'       => ['required', 'string', 'min:2', 'max:100'],
            'email'      => ['required', 'email', 'max:150', 'unique:users,email'],
            'password'   => ['required', 'string', 'min:6'],
            'phone'      => ['nullable', 'string', 'max:30'],
            'store_name' => ['nullable', 'string', 'min:2', 'max:100'],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par une autre personne.',
            'password.min' => 'Le mot de passe doit faire au moins 6 caractères.',
        ]);

        $vendor = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],
            'phone'    => $data['phone'] ?? null,
            'role'     => 'vendeur',
        ]);

        if (! empty($data['store_name'])) {
            $vendor->store()->create([
                'name' => $data['store_name'],
                'slug' => $this->uniqueSlug($data['store_name']),
                'city' => 'Bamako',
            ]);
        }

        // Compte créé par le responsable : l'abonnement est activé
        // immédiatement (30 jours), le paiement se règle en direct.
        $subscription = $vendor->subscriptions()->create([
            'amount'        => Subscription::AMOUNT,
            'duration_days' => Subscription::DURATION,
            'status'        => 'actif',
            'starts_at'     => now(),
            'ends_at'       => now()->addDays(Subscription::DURATION),
            'activated_at'  => now(),
            'activated_by'  => $admin->id,
            'note'          => 'Compte créé par le responsable du marché',
        ]);

        $subscription->events()->create([
            'user_id' => $admin->id,
            'type'    => 'activation',
            'note'    => 'Compte vendeur créé par le responsable — abonnement activé',
        ]);

        return response()->json([
            'user'         => $vendor->load(['store:id,name', 'latestSubscription']),
            'subscription' => $subscription,
        ], 201);
    }

    /**
     * Créer un compte livreur.
     * body : { name, email, password, phone }
     */
    public function createCourier(Request $request)
    {
        $this->admin($request);

        $data = $request->validate([
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'email'    => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'phone'    => ['required', 'string', 'min:6', 'max:30'],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
            'password.min' => 'Le mot de passe doit faire au moins 6 caractères.',
            'phone.required' => 'Le téléphone est indispensable : le livreur reçoit les demandes par SMS.',
        ]);

        $courier = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],
            'phone'    => $data['phone'],
            'role'     => 'livreur',
        ]);

        return response()->json(['user' => $courier], 201);
    }

    /**
     * Changer le rôle d'une personne (client ↔ vendeur ↔ livreur).
     * body : { role: client | vendeur | livreur }
     */
    public function updateRole(Request $request, User $user)
    {
        $admin = $this->admin($request);

        $data = $request->validate([
            'role' => ['required', 'in:client,vendeur,livreur'],
        ]);

        abort_if($user->role === 'admin', 403, 'Impossible de modifier le compte d\'un responsable.');

        $user->update(['role' => $data['role']]);

        if ($data['role'] === 'vendeur') {
            $encours = $user->subscriptions()
                ->whereIn('status', ['en_attente', 'actif'])
                ->exists();

            if (! $encours) {
                $subscription = $user->subscriptions()->create([
                    'amount'        => Subscription::AMOUNT,
                    'duration_days' => Subscription::DURATION,
                    'status'        => 'en_attente',
                ]);

                $subscription->events()->create([
                    'user_id' => $admin->id,
                    'type'    => 'demande',
                    'note'    => 'Rôle vendeur accordé par le responsable — abonnement à activer',
                ]);
            }
        } else {
            // Repassé client : on désactive ses abonnements en cours.
            foreach ($user->subscriptions()->whereIn('status', ['en_attente', 'actif'])->get() as $subscription) {
                $subscription->update([
                    'status'         => 'desactive',
                    'deactivated_at' => now(),
                ]);

                $subscription->events()->create([
                    'user_id' => $admin->id,
                    'type'    => 'desactivation',
                    'note'    => 'Compte repassé client par le responsable',
                ]);
            }
        }

        return response()->json([
            'user' => $user->fresh(['store:id,name', 'latestSubscription']),
        ]);
    }

    /**
     * Supprimer une personne (et sa boutique / ses produits / ses commandes).
     */
    public function deleteUser(Request $request, User $user)
    {
        $this->admin($request);

        abort_if($user->role === 'admin', 403, 'Impossible de supprimer le compte d\'un responsable.');

        $user->delete();

        return response()->json(['message' => 'Compte supprimé.']);
    }

    /**
     * Modifier son propre compte de responsable.
     * body : { name, email, phone?, password? (vide = inchangé) }
     */
    public function updateProfile(Request $request)
    {
        $user = $this->admin($request);

        $data = $request->validate([
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'email'    => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'    => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:6'],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par une autre personne.',
            'password.min' => 'Le mot de passe doit faire au moins 6 caractères.',
        ]);

        $user->update([
            'name'  => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->update(['password' => $data['password']]);
        }

        return response()->json(['user' => $user->fresh()]);
    }

    /* ---------------- abonnements vendeurs ---------------- */

    /**
     * Liste des abonnements : ?status=tous|en_attente|actif|inactif&search=&page=
     */
    public function subscriptions(Request $request)
    {
        $this->admin($request);

        $status = (string) $request->query('status', 'tous');
        $search = trim((string) $request->query('search', ''));

        $query = Subscription::query()
            ->with([
                'user:id,name,email,phone,role',
                'user.store:id,user_id,name',
            ])
            ->orderByDesc('id');

        if ($status === 'en_attente') {
            $query->where('status', 'en_attente');
        } elseif ($status === 'a_verifier') {
            $query->where('status', 'paiement_declare');
        } elseif ($status === 'actif') {
            $query->where('status', 'actif')->where('ends_at', '>', now());
        } elseif ($status === 'inactif') {
            $query->where(function ($q) {
                $q->where('status', 'desactive')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'actif')->where('ends_at', '<=', now());
                    });
            });
        }

        if ($search !== '') {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Détail d'un abonnement + journal complet (suivi pas à pas).
     */
    public function showSubscription(Request $request, Subscription $subscription)
    {
        $this->admin($request);

        $subscription->load([
            'user:id,name,email,phone,role,created_at',
            'user.store:id,user_id,name',
            'events.author:id,name,role',
        ]);

        return response()->json(['subscription' => $subscription]);
    }

    /**
     * Activer un abonnement (en attente) ou le renouveler (expiré/désactivé).
     * body : { amount?, duration_days?, note? }
     */
    public function activateSubscription(Request $request, Subscription $subscription)
    {
        $admin = $this->admin($request);

        $data = $request->validate([
            'amount'        => ['nullable', 'integer', 'min:0'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'note'          => ['nullable', 'string', 'max:255'],
        ]);

        $amount   = (int) ($data['amount'] ?? $subscription->amount ?? Subscription::AMOUNT);
        $duration = (int) ($data['duration_days'] ?? $subscription->duration_days ?? Subscription::DURATION);

        if (in_array($subscription->status, ['en_attente', 'paiement_declare'], true)) {
            $subscription->update([
                'amount'               => $amount,
                'duration_days'        => $duration,
                'status'               => 'actif',
                'starts_at'            => now(),
                'ends_at'              => now()->addDays($duration),
                'activated_at'         => now(),
                'deactivated_at'       => null,
                'activated_by'         => $admin->id,
                'note'                 => $data['note'] ?? $subscription->note,
                'payment_confirmed_at' => $subscription->payment_declared_at
                    ? ($subscription->payment_confirmed_at ?? now())
                    : $subscription->payment_confirmed_at,
                'payment_confirmed_by' => $subscription->payment_declared_at
                    ? ($subscription->payment_confirmed_by ?? $admin->id)
                    : $subscription->payment_confirmed_by,
            ]);

            $target = $subscription;
            $type   = 'activation';
        } else {
            // Période terminée : on ouvre un nouveau cycle propre.
            $target = $subscription->user->subscriptions()->create([
                'amount'        => $amount,
                'duration_days' => $duration,
                'status'        => 'actif',
                'starts_at'     => now(),
                'ends_at'       => now()->addDays($duration),
                'activated_at'  => now(),
                'activated_by'  => $admin->id,
                'note'          => $data['note'] ?? null,
            ]);

            $type = 'renouvellement';
        }

        $target->events()->create([
            'user_id' => $admin->id,
            'type'    => $type,
            'note'    => $data['note'] ?? ($type === 'activation'
                ? 'Abonnement activé par le responsable'
                    . ($subscription->payment_declared_at
                        ? ' — paiement Orange Money confirmé ('
                            . ($subscription->payer_name ?: 'vendeur') . ', '
                            . ($subscription->payer_phone ?: 'n° inconnu') . ')'
                        : '')
                : 'Renouvellement activé par le responsable'),
        ]);

        return response()->json([
            'subscription' => $target->fresh(['user:id,name', 'events.author:id,name']),
        ]);
    }

    /**
     * Désactiver un abonnement actif.
     * body : { note? }
     */
    public function deactivateSubscription(Request $request, Subscription $subscription)
    {
        $admin = $this->admin($request);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        abort_unless($subscription->status === 'actif', 422, 'Cet abonnement n\'est pas actif.');

        $subscription->update([
            'status'         => 'desactive',
            'deactivated_at' => now(),
            'note'           => $data['note'] ?? $subscription->note,
        ]);

        $subscription->events()->create([
            'user_id' => $admin->id,
            'type'    => 'desactivation',
            'note'    => $data['note'] ?? 'Abonnement désactivé par le responsable',
        ]);

        return response()->json(['subscription' => $subscription->fresh()]);
    }

    /**
     * Le paiement déclaré n'a pas été retrouvé sur le compte Orange Money :
     * on remet l'abonnement en attente et on prévient le vendeur.
     * body : { note? }
     */
    public function rejectPayment(Request $request, Subscription $subscription)
    {
        $admin = $this->admin($request);

        abort_unless($subscription->payment_declared_at, 422, 'Aucun paiement n\'a été déclaré pour cet abonnement.');

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $subscription->update([
            'status'               => 'en_attente',
            'payment_declared_at'  => null,
            'payment_confirmed_at' => null,
            'payment_confirmed_by' => null,
        ]);

        $subscription->events()->create([
            'user_id' => $admin->id,
            'type'    => 'paiement_rejete',
            'note'    => $data['note'] ?? 'Paiement Orange Money non retrouvé — vérifiez le numéro ou l\'ID de transaction',
        ]);

        return response()->json([
            'subscription' => $subscription->fresh(['user:id,name', 'events.author:id,name']),
        ]);
    }

    /* ---------------- réglages du marché (numéro Orange Money) ---------------- */

    public function settings(Request $request)
    {
        $this->admin($request);

        return response()->json(['settings' => Setting::allKnown()]);
    }

    /**
     * body : { orange_money_number?, orange_money_name? }
     */
    public function saveSettings(Request $request)
    {
        $this->admin($request);

        $data = $request->validate([
            'orange_money_number' => ['nullable', 'string', 'max:30'],
            'orange_money_name'   => ['nullable', 'string', 'max:100'],
        ]);

        Setting::put('orange_money_number', $data['orange_money_number'] ?? null);
        Setting::put('orange_money_name', $data['orange_money_name'] ?? null);

        return response()->json([
            'message'  => 'Réglages enregistrés.',
            'settings' => Setting::allKnown(),
        ]);
    }

    /* ---------------- contrôle : commandes, produits, blocage, signalements ---------------- */

    /**
     * Toutes les commandes du marché (clients + vendeurs concernés).
     * ?status=en_attente|confirmee|livree|annulee&search=
     */
    public function orders(Request $request)
    {
        $this->admin($request);

        $status = (string) $request->query('status', 'tous');
        $search = trim((string) $request->query('search', ''));

        $query = Order::query()
            ->with([
                'user:id,name,email,phone',
                'items.store:id,name',
            ])
            ->orderByDesc('id');

        if (in_array($status, ['en_attente', 'confirmee', 'livree', 'annulee'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('items', fn ($i) => $i->where('product_name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate(15));
    }

    /**
     * Le responsable change le statut d'une commande (confirmer, livrer, annuler).
     */
    public function updateOrderStatus(Request $request, Order $order)
    {
        $this->admin($request);

        $data = $request->validate([
            'status' => ['required', 'in:en_attente,confirmee,en_livraison,livree,annulee'],
        ]);

        $ancien = $order->status;
        $attrs  = ['status' => $data['status']];
        if ($data['status'] === 'confirmee' && ! $order->confirmed_at) {
            $attrs['confirmed_at'] = now();
        }
        if ($data['status'] === 'livree' && ! $order->delivered_at) {
            $attrs['delivered_at'] = now();
        }

        $order->update($attrs);

        $labels = [
            'confirmee'    => ['Commande confirmée ✅', 'Le vendeur a confirmé votre commande.'],
            'en_livraison' => ['Commande en route 🛵', 'Votre commande est en cours de livraison.'],
            'livree'       => ['Commande livrée ✅', 'Votre commande a bien été livrée.'],
            'annulee'      => ['Commande annulée', 'La commande a été annulée.'],
        ];

        if ($ancien !== $data['status'] && isset($labels[$data['status']])) {
            [$titre, $texte] = $labels[$data['status']];
            $this->notifier->to(
                $order->user,
                'order_'.$data['status'],
                $titre,
                $texte." (Commande {$order->reference})",
                $order,
                ['order_id' => $order->id]
            );
        }

        return response()->json(['order' => $order->fresh(['items.store:id,name', 'user:id,name'])]);
    }

    /**
     * Le responsable confirme aussi un paiement Orange Money.
     */
    public function confirmPayment(Request $request, Order $order)
    {
        $this->admin($request);

        $order->update(['payment_status' => 'paye', 'paid_at' => now()]);

        $this->notifier->to(
            $order->user,
            'payment_confirmed',
            'Paiement reçu 💰',
            "Votre paiement pour la commande {$order->reference} est confirmé.",
            $order,
            ['order_id' => $order->id]
        );

        return response()->json(['order' => $order->fresh()]);
    }

    /**
     * Tous les produits (y compris masqués) pour surveiller le marché.
     */
    public function products(Request $request)
    {
        $this->admin($request);

        $search = trim((string) $request->query('search', ''));

        $query = Product::query()
            ->with(['store:id,name,user_id', 'category:id,name', 'images'])
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('store', fn ($s) => $s->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate(15));
    }

    /**
     * Masquer / réafficher un produit dans le marché.
     */
    public function setProductVisibility(Request $request, Product $product)
    {
        $this->admin($request);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $product->update(['is_active' => $data['is_active']]);

        return response()->json(['product' => $product->fresh(['store:id,name'])]);
    }

    /**
     * Retirer un produit du marché.
     */
    public function deleteProduct(Request $request, Product $product)
    {
        $this->admin($request);
        $product->delete();

        return response()->json(['message' => 'Produit retiré du marché.']);
    }

    /**
     * Bloquer un compte (client ou vendeur).
     * body : { reason? }
     */
    public function blockUser(Request $request, User $user)
    {
        $admin = $this->admin($request);

        abort_if($user->id === $admin->id, 403, 'Vous ne pouvez pas bloquer votre propre compte.');
        abort_if($user->role === 'admin', 403, 'Impossible de bloquer un responsable.');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $user->update([
            'blocked_at'     => now(),
            'blocked_reason' => $data['reason'] ?? null,
        ]);

        // Plus aucune session active.
        $user->tokens()->delete();

        // Un vendeur bloqué perd son abonnement et disparaît du marché.
        if ($user->role === 'vendeur') {
            foreach ($user->subscriptions()->whereIn('status', ['en_attente', 'actif'])->get() as $subscription) {
                $subscription->update([
                    'status'         => 'desactive',
                    'deactivated_at' => now(),
                ]);

                $subscription->events()->create([
                    'user_id' => $admin->id,
                    'type'    => 'desactivation',
                    'note'    => 'Compte vendeur bloqué par le responsable'
                        . (! empty($data['reason']) ? ' — ' . $data['reason'] : ''),
                ]);
            }
        }

        return response()->json(['user' => $user->fresh(['store:id,name'])]);
    }

    /**
     * Débloquer un compte.
     */
    public function unblockUser(Request $request, User $user)
    {
        $this->admin($request);

        $user->update([
            'blocked_at'     => null,
            'blocked_reason' => null,
        ]);

        return response()->json(['user' => $user->fresh(['store:id,name'])]);
    }

    /**
     * Signalements des clients (problèmes avec un vendeur).
     */
    public function reports(Request $request)
    {
        $this->admin($request);

        $status = (string) $request->query('status', 'tous');

        $query = Report::query()
            ->with([
                'user:id,name,email,phone',
                'order:id,reference,total,status',
                'store:id,name,user_id',
                'store.user:id,name,email,blocked_at',
            ])
            ->orderByDesc('id');

        if (in_array($status, ['nouveau', 'traite'], true)) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(15));
    }

    /**
     * Marquer un signalement comme traité.
     */
    public function resolveReport(Request $request, Report $report)
    {
        $admin = $this->admin($request);

        $report->update([
            'status'     => 'traite',
            'handled_by' => $admin->id,
            'handled_at' => now(),
        ]);

        return response()->json(['report' => $report->fresh(['user:id,name', 'store:id,name'])]);
    }

    // ---------------------------------------------------------------- helpers

    private function admin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user->role === 'admin', 403, 'Réservé au responsable du marché.');

        return $user;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'boutique';
        $slug = $base;
        $i    = 1;

        while (Store::where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$i;
        }

        return $slug;
    }
}
