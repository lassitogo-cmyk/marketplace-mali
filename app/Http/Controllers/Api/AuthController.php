<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /** Hachage factice : sert à garder le même temps de réponse quand
     *  l'adresse e-mail n'existe pas (empêche de deviner les comptes). */
    private const HASH_FACTICE = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

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
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'phone'    => ['nullable', 'string', 'max:30'],
            'role'     => ['nullable', 'in:client'],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée. Connectez-vous ou choisissez une autre.',
            'password.min' => 'Le mot de passe doit faire au moins 8 caractères.',
            'password.max' => 'Le mot de passe ne doit pas dépasser 72 caractères.',
            'role.in'      => 'Seuls les comptes clients peuvent être créés ici. Les vendeurs sont inscrits par le responsable du marché.',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => mb_strtolower($data['email']),
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
            'email'    => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        $email = mb_strtolower(trim($credentials['email']));

        // Comparaison insensible à la casse (PostgreSQL est sensible).
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            // Même temps de calcul que pour un compte existant.
            Hash::check($credentials['password'], self::HASH_FACTICE);
            $this->journaliserEchec($request, $email, 'compte inconnu');

            return response()->json(['message' => 'E-mail ou mot de passe incorrect.'], 401);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $this->journaliserEchec($request, $email, 'mot de passe incorrect');

            return response()->json(['message' => 'E-mail ou mot de passe incorrect.'], 401);
        }

        // Compte bloqué par le responsable du marché.
        if ($user->estBloque()) {
            $this->journaliserEchec($request, $email, 'compte bloqué');

            return response()->json([
                'message' => 'Votre compte est bloqué par le responsable du marché.'
                    . ($user->blocked_reason ? ' Motif : ' . $user->blocked_reason : ''),
            ], 403);
        }

        // Journal des connexions du responsable (traçabilité).
        if ($user->role === 'admin') {
            Log::info('Connexion responsable', ['email' => $email, 'ip' => $request->ip()]);
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

    /**
     * Modifier son propre profil (client, vendeur, livreur).
     * body : { name, email, phone? }
     */
    public function updateMe(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['required', 'string', 'min:2', 'max:100'],
            'email' => [
                'required', 'email', 'max:150',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par une autre personne.',
        ]);

        $user->update([
            'name'  => $data['name'],
            'email' => mb_strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
        ]);

        $payload = ['message' => 'Informations enregistrées.', 'user' => $user->fresh()];

        if ($user->role === 'vendeur') {
            $payload['store'] = $user->store()->withCount('products')->first();
        }

        return response()->json($payload);
    }

    /**
     * Changer son propre mot de passe.
     * body : { current_password, password, password_confirmation }
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'current_password.required' => 'Indiquez votre mot de passe actuel.',
            'password.min'              => 'Le nouveau mot de passe doit faire au moins 8 caractères.',
            'password.confirmed'        => 'Les deux mots de passe ne correspondent pas.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Votre mot de passe actuel est incorrect.'], 422);
        }

        $user->update(['password' => $data['password']]);

        // Les autres appareils connectés sont déconnectés par sécurité.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Mot de passe changé.']);
    }

    /** Note chaque tentative refusée (sans jamais écrire le mot de passe). */
    private function journaliserEchec(Request $request, string $email, string $motif): void
    {
        Log::warning('Tentative de connexion refusée', [
            'email' => $email,
            'motif' => $motif,
            'ip'    => $request->ip(),
        ]);
    }
}
