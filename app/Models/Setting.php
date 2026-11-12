<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglages simples du marché (clé / valeur).
 * Ex. numéro Orange Money du responsable.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Clés connues et leur valeur par défaut. */
    public const DEFAULTS = [
        'orange_money_number' => null,
        'orange_money_name'   => null,
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();

        return $row?->value ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Tous les réglages connus, prêts pour l'interface. */
    public static function allKnown(): array
    {
        $out = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }
}
