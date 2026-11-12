<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importe database/seeders/marketplace-data.json dans la base courante.
 * Utilisé au premier démarrage sur le serveur en ligne (Render / Neon).
 */
class MarketplaceImport extends Command
{
    protected $signature = 'marketplace:import {--if-empty : ne rien faire si des comptes existent déjà}';

    protected $description = 'Importe les données du marché depuis database/seeders/marketplace-data.json';

    public function handle(): int
    {
        $path = database_path('seeders/marketplace-data.json');

        if (! is_file($path)) {
            $this->warn('Aucun fichier à importer : ' . $path);

            return self::SUCCESS;
        }

        if ($this->option('if-empty') && Schema::hasTable('users') && DB::table('users')->exists()) {
            $this->info('La base contient déjà des comptes — import ignoré.');

            return self::SUCCESS;
        }

        $dump = json_decode(file_get_contents($path), true);

        if (! is_array($dump)) {
            $this->error('Fichier JSON illisible.');

            return self::FAILURE;
        }

        foreach (MarketplaceExport::TABLES as $table) {
            $rows = $dump[$table] ?? [];
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                // insertOrIgnore : les réglages sont déjà créés par la migration,
                // et un import partiel ne doit pas s'arrêter sur un doublon.
                DB::table($table)->insertOrIgnore($chunk);
            }

            $this->line($table . ' : ' . count($rows) . ' ligne(s)');
        }

        $this->resetSequences();

        $this->info('Import terminé.');

        return self::SUCCESS;
    }

    /**
     * Après un import avec identifiants explicites, PostgreSQL doit avancer
     * ses compteurs, sinon les nouveaux enregistrements entrent en conflit.
     */
    private function resetSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (MarketplaceExport::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement(
                'SELECT setval(pg_get_serial_sequence(?, \'id\'), COALESCE((SELECT MAX(id) FROM "' . $table . '"), 1))',
                [$table]
            );
        }
    }
}
