<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'reference',
        'status',
        'payment_status',
        'payment_ref',
        'paid_at',
        'confirmed_at',
        'delivered_at',
        'courier_id',
        'total',
        'address',
        'phone',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'total'        => 'integer',
            'paid_at'      => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function courier()
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Signalements liés à cette commande. */
    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Remet en stock les produits d'une commande annulée.
     * À appeler uniquement quand la commande passe à « annulee ».
     */
    public function restoreStock(): void
    {
        $quantities = $this->items()
            ->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(qty) as total_qty')
            ->groupBy('product_id')
            ->pluck('total_qty', 'product_id');

        foreach ($quantities as $productId => $qty) {
            Product::whereKey($productId)->increment('stock', (int) $qty);
        }
    }
}
