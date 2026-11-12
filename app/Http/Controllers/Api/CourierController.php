<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Notifier;
use Illuminate\Http\Request;

/**
 * Espace livreur : reçoit les commandes confirmées par le vendeur,
 * les prend en charge puis confirme la livraison.
 */
class CourierController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    protected function courier(Request $request)
    {
        $user = $request->user();

        abort_unless($user->role === 'livreur', 403, 'Espace réservé aux livreurs.');

        return $user;
    }

    /**
     * Commandes à livrer : confirmées (libres) + celles prises en charge par moi.
     */
    public function orders(Request $request)
    {
        $user = $this->courier($request);

        $orders = Order::with([
            'user:id,name,phone',
            'items:id,order_id,store_id,product_name,variant,qty,unit_price,subtotal',
            'items.store:id,name,phone',
        ])
            ->where(function ($q) use ($user) {
                $q->where('status', 'confirmee')
                    ->orWhere(function ($q2) use ($user) {
                        $q2->where('status', 'en_livraison')->where('courier_id', $user->id);
                    });
            })
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $orders]);
    }

    /** Le livreur prend la commande en charge. */
    public function accept(Request $request, Order $order)
    {
        $user = $this->courier($request);

        abort_unless($order->status === 'confirmee', 422, 'Cette commande n\'est pas prête à être livrée.');
        abort_if($order->courier_id && $order->courier_id !== $user->id, 409, 'Un autre livreur a déjà pris cette commande.');

        $order->update([
            'courier_id' => $user->id,
            'status'     => 'en_livraison',
        ]);

        $this->notifier->to(
            $order->user,
            'order_shipped',
            'Commande en route 🛵',
            "Votre commande {$order->reference} a été prise en charge par {$user->name} et arrive.",
            $order,
            ['order_id' => $order->id]
        );

        $this->notifier->toMany(
            $this->vendors($order),
            'order_shipped',
            'Commande en livraison',
            "{$user->name} livre la commande {$order->reference}.",
            $order
        );

        return response()->json(['order' => $order->fresh(['user:id,name,phone', 'items.store:id,name,phone'])]);
    }

    /** Le livreur confirme la remise au client. */
    public function deliver(Request $request, Order $order)
    {
        $user = $this->courier($request);

        abort_unless($order->courier_id === $user->id, 403, 'Cette commande ne vous est pas attribuée.');

        $order->update([
            'status'       => 'livree',
            'delivered_at' => now(),
        ]);

        $this->notifier->to(
            $order->user,
            'order_delivered',
            'Commande livrée ✅',
            "Votre commande {$order->reference} a été livrée. Merci d'avoir acheté sur Marketplace Mali !",
            $order
        );

        $this->notifier->toMany(
            $this->vendors($order),
            'order_delivered',
            'Commande livrée',
            "La commande {$order->reference} a été remise au client.",
            $order
        );

        return response()->json(['order' => $order->fresh()]);
    }

    protected function vendors(Order $order)
    {
        return \App\Models\User::whereIn(
            'id',
            $order->items()->with('store:id,user_id')->get()->pluck('store.user_id')->filter()->unique()
        )->get();
    }
}
