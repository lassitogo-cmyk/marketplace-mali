<?php

namespace App\Services;

use App\Mail\OrderUpdateMail;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Centre de notifications du marché.
 *
 * Un seul appel crée :
 *   • une notification dans l'application (cloche, rafraîchie toutes les 20 s) ;
 *   • un e-mail si le destinataire a une adresse ;
 *   • un SMS si le destinataire a un téléphone (fournisseur configurable).
 *
 * Aucune erreur d'envoi ne doit bloquer la commande : tout est encapsulé.
 */
class Notifier
{
    public function __construct(protected Sms $sms) {}

    public function to(
        ?User $user,
        string $type,
        string $title,
        string $body = '',
        ?Order $order = null,
        array $data = []
    ): void {
        if (! $user) {
            return;
        }

        try {
            Notification::create([
                'user_id'  => $user->id,
                'type'     => $type,
                'title'    => $title,
                'body'     => $body !== '' ? $body : null,
                'order_id' => $order?->id,
                'data'     => $data ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification interne non enregistrée : '.$e->getMessage());
        }

        if ($user->email) {
            try {
                Mail::to($user->email)->send(new OrderUpdateMail($title, $body, $order, $user));
            } catch (\Throwable $e) {
                Log::warning('E-mail non envoyé à '.$user->email.' : '.$e->getMessage());
            }
        }

        if ($user->phone) {
            $this->sms->send($user->phone, trim($title.($body !== '' ? ' — '.$body : '')));
        }
    }

    /** Plusieurs destinataires d'un coup (en ignorant les doublons et l'expéditeur). */
    public function toMany(iterable $users, string $type, string $title, string $body = '', ?Order $order = null, array $data = []): void
    {
        $seen = [];

        foreach ($users as $user) {
            if (! $user instanceof User || isset($seen[$user->id])) {
                continue;
            }

            $seen[$user->id] = true;
            $this->to($user, $type, $title, $body, $order, $data);
        }
    }

    /** Tous les responsables du marché. */
    public function admins(): \Illuminate\Support\Collection
    {
        return User::where('role', 'admin')->get();
    }

    /** Tous les livreurs disponibles. */
    public function couriers(): \Illuminate\Support\Collection
    {
        return User::where('role', 'livreur')->get();
    }

    /** Livreurs d'une boutique précise (les seuls à recevoir ses courses). */
    public function couriersOfStore(int $storeId): \Illuminate\Support\Collection
    {
        return User::where('role', 'livreur')
            ->where('store_id', $storeId)
            ->get();
    }
}
