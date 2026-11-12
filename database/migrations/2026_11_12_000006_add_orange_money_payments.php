<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement de l'abonnement par Orange Money :
 * - réglages du marché (numéro Orange Money du responsable) dans `settings` ;
 * - preuve de paiement déclarée par le vendeur sur son abonnement
 *   (qui a payé, depuis quel numéro, ID de transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Numéro Orange Money du responsable du marché (modifiable dans son espace).
        \Illuminate\Support\Facades\DB::table('settings')->insert([
            ['key' => 'orange_money_number', 'value' => '94503605',    'created_at' => now(), 'updated_at' => now()],
            ['key' => 'orange_money_name',   'value' => 'Lassine Togo', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('payer_name', 100)->nullable()->after('note');
            $table->string('payer_phone', 30)->nullable()->after('payer_name');
            $table->string('payment_ref', 100)->nullable()->after('payer_phone');
            $table->timestamp('payment_declared_at')->nullable()->after('payment_ref');
            $table->timestamp('payment_confirmed_at')->nullable()->after('payment_declared_at');
            $table->foreignId('payment_confirmed_by')->nullable()->after('payment_confirmed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['payment_confirmed_by']);
            $table->dropColumn([
                'payer_name',
                'payer_phone',
                'payment_ref',
                'payment_declared_at',
                'payment_confirmed_at',
                'payment_confirmed_by',
            ]);
        });

        Schema::dropIfExists('settings');
    }
};
