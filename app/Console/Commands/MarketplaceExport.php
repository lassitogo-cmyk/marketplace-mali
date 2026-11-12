<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exporte toutes les données du marché dans database/seeders/marketplace-data.json
 * (comptes, boutiques, produits, photos en base, commandes, abonnements…).
 *
 * Ce fichier est ensuite importé automatiquement sur le serveur en ligne.
 */
class MarketplaceExport extends Command
{
    protected $signature = 'marketplace:export';

    protected $description = 'Exporte les données du marché dans un fichier JSON (avant déploiement)';

    /** Ordre important : les parents avant les enfants. */
    public const TABLES = [
        'users',
        'categories',
        'stores',
        'products',
        'product_images',
        'orders',
        'order_items',
        'subscriptions',
        'subscription_events',
        'reports',
        'settings',
        'media',
    ];

    public function handle(): int
    {
        $dump = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $dump[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        $path = database_path('seeders/marketplace-data.json');
        $json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($path, $json);

        $counts = collect($dump)->map(fn ($rows, $table) => $table . '=' . count($rows))->implode(', ');

        $this->info('Export écrit : ' . $path);
        $this->line($counts);

        return self::SUCCESS;
    }
}
