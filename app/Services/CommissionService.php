<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\Store;

/**
 * Calcule et enregistre la commission du marché (10 % par défaut)
 * sur chaque commande livrée.
 *
 * Le client paie le vendeur par Orange Money. L'application retient
 * automatiquement la part du marché et la présente au vendeur comme
 * une somme à verser au responsable (qui valide la réception).
 */
class CommissionService
{
    /** Taux actuel, borné entre 0 et 50 %. */
    public static function rate(): int
    {
        $rate = (int) Setting::get('commission_rate', 10);

        return max(0, min(50, $rate));
    }

    /**
     * Crée (une seule fois) les commissions d'une commande livrée,
     * une par boutique présente dans la commande.
     */
    public static function forOrder(Order $order): void
    {
        if ($order->status !== 'livree') {
            return;
        }

        $rate = self::rate();
        if ($rate === 0) {
            return;
        }

        $lines = OrderItem::where('order_id', $order->id)
            ->selectRaw('store_id, sum(subtotal) as base')
            ->groupBy('store_id')
            ->get();

        foreach ($lines as $line) {
            $storeId = (int) $line->store_id;
            if ($storeId <= 0) {
                continue;
            }

            $store = Store::find($storeId);
            if (! $store || ! $store->user_id) {
                continue;
            }

            $base   = (int) $line->base;
            $amount = (int) round($base * $rate / 100);

            Commission::firstOrCreate(
                ['order_id' => $order->id, 'store_id' => $storeId],
                [
                    'user_id' => $store->user_id,
                    'rate'    => $rate,
                    'base'    => $base,
                    'amount'  => $amount,
                    'status'  => 'due',
                ]
            );
        }
    }

    /**
     * Résumé des commissions d'un vendeur (par boutique).
     *
     * @return array{rate:int,due:int,declaree:int,payee:int,count_due:int,count_declaree:int,count_payee:int}
     */
    public static function summary(int $storeId): array
    {
        $rows = Commission::where('store_id', $storeId)
            ->selectRaw("status, sum(amount) as total, count(*) as nombre")
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $get = fn (string $status, string $field) => (int) ($rows->get($status)->{$field} ?? 0);

        return [
            'rate'           => self::rate(),
            'due'            => $get('due', 'total'),
            'declaree'       => $get('declaree', 'total'),
            'payee'          => $get('payee', 'total'),
            'count_due'      => $get('due', 'nombre'),
            'count_declaree' => $get('declaree', 'nombre'),
            'count_payee'    => $get('payee', 'nombre'),
        ];
    }
}
