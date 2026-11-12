<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    /** Tarif de l'abonnement vendeur (FCFA). */
    public const AMOUNT = 10000;

    /** Durée d'un abonnement (jours). */
    public const DURATION = 30;

    protected $fillable = [
        'user_id',
        'amount',
        'duration_days',
        'status',
        'starts_at',
        'ends_at',
        'activated_at',
        'deactivated_at',
        'activated_by',
        'note',
        'payer_name',
        'payer_phone',
        'payment_ref',
        'payment_declared_at',
        'payment_confirmed_at',
        'payment_confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'           => 'datetime',
            'ends_at'             => 'datetime',
            'activated_at'        => 'datetime',
            'deactivated_at'      => 'datetime',
            'payment_declared_at' => 'datetime',
            'payment_confirmed_at'=> 'datetime',
            'amount'              => 'integer',
            'duration_days'       => 'integer',
        ];
    }

    protected $appends = ['statut', 'jours_restants'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function activatedBy()
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function events()
    {
        return $this->hasMany(SubscriptionEvent::class)->orderBy('id');
    }

    /**
     * Statut réel : un abonnement « actif » dont la date de fin est
     * passée est automatiquement considéré comme expiré.
     */
    public function getStatutAttribute(): string
    {
        if ($this->status === 'actif' && $this->ends_at && $this->ends_at->isPast()) {
            return 'expire';
        }

        return $this->status;
    }

    /** Nombre de jours restants (0 si inactif ou expiré). */
    public function getJoursRestantsAttribute(): int
    {
        if ($this->status !== 'actif' || ! $this->ends_at) {
            return 0;
        }

        return max(0, (int) ceil(($this->ends_at->getTimestamp() - now()->getTimestamp()) / 86400));
    }

    public function estActif(): bool
    {
        return $this->statut === 'actif';
    }

    /** Le vendeur a-t-il déclaré un paiement Orange Money à vérifier ? */
    public function paiementDeclare(): bool
    {
        return $this->payment_declared_at !== null && $this->payment_confirmed_at === null;
    }
}
