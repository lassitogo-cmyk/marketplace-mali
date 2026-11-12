<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les vendeurs déjà présents à la mise en place du système reçoivent
 * un abonnement actif de 30 jours offert, pour ne pas disparaître
 * du marché du jour au lendemain.
 */
return new class extends Migration
{
    public function up(): void
    {
        $vendeurs = DB::table('users')->where('role', 'vendeur')->get();

        foreach ($vendeurs as $vendeur) {
            $existe = DB::table('subscriptions')->where('user_id', $vendeur->id)->exists();

            if ($existe) {
                continue;
            }

            $id = DB::table('subscriptions')->insertGetId([
                'user_id'        => $vendeur->id,
                'amount'         => 10000,
                'duration_days'  => 30,
                'status'         => 'actif',
                'starts_at'      => now(),
                'ends_at'        => now()->addDays(30),
                'activated_at'   => now(),
                'deactivated_at' => null,
                'activated_by'   => null,
                'note'           => 'Abonnement de lancement (offert)',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::table('subscription_events')->insert([
                'subscription_id' => $id,
                'user_id'         => null,
                'type'            => 'activation',
                'note'            => 'Abonnement de lancement offert à la mise en place',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Migration de données : rien à défaire.
    }
};
