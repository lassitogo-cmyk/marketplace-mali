<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal pas à pas d'un abonnement : demande, activation,
 * désactivation, renouvellement… qui a fait quoi et quand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // auteur de l'action
            $table->string('type'); // demande | activation | desactivation | renouvellement | expiration
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
