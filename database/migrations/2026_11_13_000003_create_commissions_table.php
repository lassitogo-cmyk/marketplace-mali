<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commission du marché : 10 % (par défaut) du montant de chaque
     * commande livrée, due par le vendeur au responsable.
     */
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // vendeur
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rate')->default(10);
            $table->unsignedInteger('base');    // montant des articles de la boutique
            $table->unsignedInteger('amount');  // part du marché
            $table->string('status', 20)->default('due'); // due | declaree | payee
            $table->string('payer_name', 100)->nullable();
            $table->string('payer_phone', 30)->nullable();
            $table->string('payment_ref', 100)->nullable();
            $table->timestamp('declared_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            // Une seule commission par commande et par boutique.
            $table->unique(['order_id', 'store_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
