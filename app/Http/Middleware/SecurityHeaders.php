<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité envoyés avec chaque réponse.
 *
 * - empêche l'affichage du site dans une iframe (clickjacking) ;
 * - empêche le navigateur de deviner le type des fichiers (XSS via upload) ;
 * - limite les sources de scripts et de styles (CSP) ;
 * - interdit l'accès à la caméra, au micro et à la position ;
 * - impose HTTPS pour un an (HSTS) quand la requête est sécurisée.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options'      => 'nosniff',
            'X-Frame-Options'             => 'DENY',
            'Referrer-Policy'             => 'strict-origin-when-cross-origin',
            'Permissions-Policy'          => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy'  => 'same-origin',
            'Content-Security-Policy'     => implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "img-src 'self' data: blob:",
                "style-src 'self' 'unsafe-inline'",
                "script-src 'self' 'unsafe-inline'",
                "connect-src 'self'",
                "font-src 'self' data:",
                "manifest-src 'self'",
                "media-src 'self'",
                "worker-src 'self'",
            ]),
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        // Ne jamais révéler la version de PHP / Laravel.
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
