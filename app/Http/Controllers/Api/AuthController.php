<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Inscription publique : CLIENT uniquement → renvoie un jeton Sanctum.
     * Les comptes vendeurs sont créés exclusivement par le responsable
     * du marché (POST /api/admin/vendor).
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'email'    => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'phone'    => ['nullable', 'string', 'max:30'],
            'role'     => ['nullable', 'in:client'],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée. Connectez-vous ou choisissez une autre.',
            'password.min' => 'Le mot de passe doit faire au moins 6 caractères.',
            'role.in'      => 'Seuls les comptes clients peuvent être créés ici. Les vendeurs sont inscrits par le responsable du marché.',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],
            'phone'    => $data['phone'] ?? null,
            'role'     => 'client',
        ]);

        $payload = [
            'token' => $user->createToken('mobile-app')->plainTextToken,
            'user'  => $user,
        ];

        return response()->json($payload, 201);
    }

    /**
     * Connexion → jeton Sanctum + utilisateur (+ boutique si vendeur).
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'E-mail ou mot de passe incorrect.'], 401);
        }

        // Compte bloqué par le responsable du marché.
        if ($user->estBloque()) {
            return response()->json([
                'message' => 'Votre compte est bloqué par le responsable du marché.'
                    . ($user->blocked_reason ? ' Motif : ' . $user->blocked_reason : ''),
            ], 403);
        }

        $payload = [
            'token' => $user->createToken('mobile-app')->plainTextToken,
            'user'  => $user,
        ];

        if ($user->role === 'vendeur') {
            $payload['store'] = $user->store()->withCount('products')->first();
        }

        return response()->json($payload);
    }

    /**
     * Déconnexion : supprime le jeton courant.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /**
     * Profil de l'utilisateur connecté.
     */
    public function me(Request $request)
    {
        $payload = ['user' => $request->user()];

        if ($request->user()->role === 'vendeur') {
            $payload['store'] = $request->user()->store()->withCount('products')->first();
        }

        return response()->json($payload);
    }
}
