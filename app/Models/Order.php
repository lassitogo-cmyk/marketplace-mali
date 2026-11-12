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
}
