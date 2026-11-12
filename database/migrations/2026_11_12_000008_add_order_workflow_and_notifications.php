<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circuit de commande complet + notifications.
 *
 * Statuts de commande :
 *   en_attente   → le vendeur doit confirmer
 *   confirmee    → le client paie par Orange Money
 *   en_livraison → le livreur a pris la commande
 *   livree       → remise au client
 *   annulee
 *
 * Paiement de la commande :
 *   a_payer → declare (le client a envoyé l'argent) → paye (vendeur confirme)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status')->default('a_payer');
            $table->string('payment_ref')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('courier_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_id');
            $table->dropColumn(['payment_status', 'payment_ref', 'paid_at', 'confirmed_at', 'delivered_at']);
        });
    }
};
