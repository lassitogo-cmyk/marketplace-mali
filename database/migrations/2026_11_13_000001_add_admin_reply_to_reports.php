<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des signalements par le responsable :
 * - sa réponse visible par le client et le vendeur ;
 * - la date à laquelle le vendeur a été prévenu par SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->text('admin_reply')->nullable()->after('message');
            $table->timestamp('vendor_notified_at')->nullable()->after('handled_at');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['admin_reply', 'vendor_notified_at']);
        });
    }
};
