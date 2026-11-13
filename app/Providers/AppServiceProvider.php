<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // En production le site est toujours servi en HTTPS :
        // les liens générés (photos, redirections) le sont aussi.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->limites();
    }

    /**
     * Limitation de débit — protège contre le force brute, le spam
     * de commandes et le téléchargement massif des photos.
     */
    protected function limites(): void
    {
        // Toutes les routes /api : 180 requêtes / minute et par personne.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->id ?: $request->ip()));

        // Connexion : 5 essais / minute par e-mail + IP, 10 / 15 min
        // par e-mail (même si l'attaquant change d'adresse IP), 40 / min
        // par IP (empêche de tester des milliers de comptes).
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $ip    = $request->ip();

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$ip),
                Limit::perMinutes(15, 10)->by('login-email:'.$email),
                Limit::perMinute(100)->by('login-ip:'.$ip),
            ];
        });

        // Inscription : 5 comptes par heure et par IP.
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)
            ->by('register:'.$request->ip()));

        // Commandes : 20 par minute et par client.
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(20)
            ->by('orders:'.($request->user()?->id ?: $request->ip())));

        // Photos : 300 / minute par IP (empêche l'aspiration du catalogue).
        RateLimiter::for('media', fn (Request $request) => Limit::perMinute(600)
            ->by('media:'.$request->ip()));
    }
}
