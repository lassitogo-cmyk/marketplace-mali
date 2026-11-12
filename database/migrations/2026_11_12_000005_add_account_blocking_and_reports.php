<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôle du marché par le responsable :
 * - blocage d'un compte (client ou vendeur) ;
 * - signalements d'un client contre un vendeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('phone');
            $table->string('blocked_reason', 255)->nullable()->after('blocked_at');
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();          // client qui signale
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); // commande concernée
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); // boutique visée
            $table->text('message');
            $table->string('status', 20)->default('nouveau'); // nouveau | traite
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_reason']);
        });
    }
};
