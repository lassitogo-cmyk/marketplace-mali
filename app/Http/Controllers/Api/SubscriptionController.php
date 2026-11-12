<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Abonnement vu par le vendeur : son état, son suivi pas à pas,
 * et l'envoi d'une demande (inscription ou renouvellement).
 * C'est le responsable du marché qui active ou désactive.
 */
class SubscriptionController extends Controller
{
    /**
     * Mon abonnement (plan, état actuel, journal des étapes, boutique).
     */
    public function mine(Request $request)
    {
        $user = $this->vendor($request);

        $subscription = $user->subscriptions()->latest('id')->first();

        return response()->json([
            'plan'         => [
                'amount'        => Subscription::AMOUNT,
                'duration_days' => Subscription::DURATION,
            ],
            'subscription' => $subscription
                ? $subscription->load('events.author:id,name,role')
                : null,
            'paiement'     => $this->paymentInfo($subscription),
            'orange_money' => $this->orangeMoney(),
            'store'        => $user->store()->withCount('products')->first(),
        ]);
    }

    /**
     * Numéro Orange Money du responsable, affiché au vendeur pour payer.
     */
    public function orangeMoney()
    {
        return [
            'number' => Setting::get('orange_money_number'),
            'name'   => Setting::get('orange_money_name'),
        ];
    }

    /**
     * Le vendeur a envoyé l'argent par Orange Money : il déclare
     * « qui a payé » (nom + numéro) et l'ID de transaction.
     * Le responsable vérifie sur son compte puis active l'abonnement.
     */
    public function declarerPaiement(Request $request)
    {
        $user = $this->vendor($request);

        $data = $request->validate([
            'payer_name'  => ['required', 'string', 'min:2', 'max:100'],
            'payer_phone' => ['required', 'string', 'min:6', 'max:30'],
            'payment_ref' => ['nullable', 'string', 'max:100'],
        ], [
            'payer_name.required'  => 'Indiquez le nom de la personne qui a envoyé l\'argent.',
            'payer_phone.required' => 'Indiquez le numéro Orange Money utilisé pour le paiement.',
        ]);

        $subscription = $user->subscriptions()->latest('id')->first();

        // Pas de demande en cours : on en ouvre une automatiquement.
        if (! $subscription || ! in_array($subscription->status, ['en_attente', 'paiement_declare'], true)) {
            if ($subscription && $subscription->status === 'actif' && $subscription->ends_at?->isFuture()) {
                return response()->json(['message' => 'Votre abonnement est déjà actif.'], 422);
            }

            $subscription = $user->subscriptions()->create([
                'amount'        => Subscription::AMOUNT,
                'duration_days' => Subscription::DURATION,
                'status'        => 'en_attente',
            ]);

            $subscription->events()->create([
                'user_id' => $user->id,
                'type'    => 'demande',
                'note'    => 'Demande d\'abonnement créée pour le paiement Orange Money',
            ]);
        }

        $subscription->update([
            'status'               => 'paiement_declare',
            'payer_name'           => $data['payer_name'],
            'payer_phone'          => $data['payer_phone'],
            'payment_ref'          => $data['payment_ref'] ?? null,
            'payment_declared_at'  => now(),
            'payment_confirmed_at' => null,
            'payment_confirmed_by' => null,
        ]);

        $subscription->events()->create([
            'user_id' => $user->id,
            'type'    => 'paiement',
            'note'    => 'Paiement Orange Money déclaré par ' . $data['payer_name']
                . ' (' . $data['payer_phone'] . ')'
                . (! empty($data['payment_ref']) ? ' — ID transaction : ' . $data['payment_ref'] : ''),
        ]);

        return response()->json([
            'message'      => 'Merci ! Le responsable du marché vérifie le paiement et active votre abonnement.',
            'subscription' => $subscription->fresh()->load('events.author:id,name,role'),
            'paiement'     => $this->paymentInfo($subscription),
        ]);
    }

    /** Résumé du paiement déclaré (ou null). */
    private function paymentInfo(?Subscription $subscription): ?array
    {
        if (! $subscription || ! $subscription->payment_declared_at) {
            return null;
        }

        return [
            'payer_name'           => $subscription->payer_name,
            'payer_phone'          => $subscription->payer_phone,
            'payment_ref'          => $subscription->payment_ref,
            'declared_at'          => $subscription->payment_declared_at,
            'confirmed_at'         => $subscription->payment_confirmed_at,
            'en_verification'      => $subscription->paiementDeclare(),
        ];
    }

    /**
     * Envoyer une demande d'abonnement / de renouvellement.
     * (Si une demande est déjà en attente ou si l'abonnement est actif,
     * on renvoie l'abonnement existant sans créer de doublon.)
     */
    public function demander(Request $request)
    {
        $user = $this->vendor($request);

        $encours = $user->subscriptions()
            ->where(function ($q) {
                $q->where('status', 'en_attente')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'actif')->where('ends_at', '>', now());
                    });
            })
            ->latest('id')
            ->first();

        if ($encours) {
            return response()->json([
                'message'      => $encours->status === 'en_attente'
                    ? 'Votre demande est déjà en attente d\'activation.'
                    : 'Votre abonnement est déjà actif.',
                'subscription' => $encours,
            ]);
        }

        $subscription = $user->subscriptions()->create([
            'amount'        => Subscription::AMOUNT,
            'duration_days' => Subscription::DURATION,
            'status'        => 'en_attente',
        ]);

        $subscription->events()->create([
            'user_id' => $user->id,
            'type'    => 'demande',
            'note'    => 'Demande de renouvellement envoyée par le vendeur',
        ]);

        return response()->json(['subscription' => $subscription], 201);
    }

    // ---------------------------------------------------------------- helpers

    private function vendor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user->role === 'vendeur', 403, 'Réservé aux vendeurs.');

        return $user;
    }
}
