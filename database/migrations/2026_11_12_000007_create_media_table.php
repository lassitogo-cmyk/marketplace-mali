<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos stockées dans la base (et non sur le disque) : indispensable sur
 * un hébergement gratuit comme Render, dont le disque est éphémère.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('mime', 100)->default('application/octet-stream');
            $table->string('name', 190)->nullable();
            $table->longText('data');          // contenu du fichier encodé en base64
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
