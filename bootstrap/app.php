<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render (et la plupart des hébergeurs) place un proxy HTTPS devant
        // l'application : on lui fait confiance pour connaître le vrai schéma.
        $middleware->trustProxies(at: '*');

        // En-têtes de sécurité sur toutes les réponses.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Limite générale de l'API (définie dans AppServiceProvider).
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Message clair quand un utilisateur dépasse la limite de tentatives.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Trop de tentatives. Patientez une minute avant de réessayer.',
                ], 429, $e->getHeaders());
            }

            return null;
        });
    })->create();
