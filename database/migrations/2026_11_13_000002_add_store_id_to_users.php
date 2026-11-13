<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque livreur appartient à une boutique (son vendeur l'a créé).
 * Un livreur ne voit et ne livre que les commandes de sa boutique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('role')
                ->constrained('stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
