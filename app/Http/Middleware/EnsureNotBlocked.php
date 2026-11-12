<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte bloqué par le responsable du marché ne peut plus rien
 * faire : ses requêtes authentifiées sont refusées immédiatement.
 */
class EnsureNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->blocked_at) {
            $user->currentAccessToken()?->delete();
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Votre compte est bloqué par le responsable du marché.'
                    . ($user->blocked_reason ? ' Motif : ' . $user->blocked_reason : ''),
            ], 403);
        }

        return $next($request);
    }
}
