<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionEvent extends Model
{
    protected $fillable = [
        'subscription_id',
        'user_id',
        'type',
        'note',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Auteur de l'action (responsable du marché ou vendeur). */
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
