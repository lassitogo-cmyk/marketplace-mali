<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'store_id',
        'blocked_at',
        'blocked_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'blocked_at'        => 'datetime',
        ];
    }

    public function isVendor(): bool
    {
        return $this->role === 'vendeur';
    }

    public function isCourier(): bool
    {
        return $this->role === 'livreur';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Notifications reçues dans l'application. */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function store()
    {
        return $this->hasOne(Store::class);
    }

    /** Boutique à laquelle appartient un livreur. */
    public function boutique()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Commandes que ce livreur a prises en charge. */
    public function deliveries()
    {
        return $this->hasMany(Order::class, 'courier_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /** Signalements déposés par ce client. */
    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    /** Le compte est-il bloqué par le responsable du marché ? */
    public function estBloque(): bool
    {
        return $this->blocked_at !== null;
    }

    /** Tous les abonnements (historique complet). */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /** Le dernier abonnement (pour l'affichage dans les listes). */
    public function latestSubscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /** L'abonnement actif à cet instant, s'il existe. */
    public function abonnementActif(): ?Subscription
    {
        return $this->subscriptions()
            ->where('status', 'actif')
            ->where('ends_at', '>', now())
            ->latest('ends_at')
            ->first();
    }
}
