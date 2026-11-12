<?php

use Illuminate\Support\Facades\Route;

// La racine du serveur ouvre directement le marché web (marche.html).
Route::redirect('/', '/marche.html');

// Photos stockées en base (persistantes même si le disque du serveur est vidé).
Route::get('/media/{media}', [\App\Http\Controllers\MediaController::class, 'show']);
