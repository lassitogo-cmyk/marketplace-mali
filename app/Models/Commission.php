<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Commission du marché sur une commande livrée (10 % par défaut).
 * Le client paie le vendeur ; le vendeur doit ensuite verser cette
 * part au responsable du marché par Orange Money.
 */
class Commission extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'order_id',
        'rate',
        'base',
        'amount',
        'status',
        'payer_name',
        'payer_phone',
        'payment_ref',
        'declared_at',
        'paid_at',
        'confirmed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'rate'        => 'integer',
            'base'        => 'integer',
            'amount'      => 'integer',
            'declared_at' => 'datetime',
            'paid_at'     => 'datetime',
        ];
    }

    public const STATUSES = [
        'due'      => ['label' => 'À verser',   'color' => '#B45309', 'bg' => '#FEF3C7'],
        'declaree' => ['label' => 'Déclarée',   'color' => '#1D4ED8', 'bg' => '#DBEAFE'],
        'payee'    => ['label' => 'Encaissée',  'color' => '#15803D', 'bg' => '#DCFCE7'],
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function getStatutAttribute(): string
    {
        return $this->status;
    }
}
