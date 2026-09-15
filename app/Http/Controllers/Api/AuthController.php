<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    /**
     * Durée de vie du token temporaire "en attente de 2FA", en minutes.
     */
    private const TWO_FACTOR_PENDING_TTL = 5;

    /**
     * Register a new admin user.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole('admin');

        $token = $user->createToken('auth_token')->plainTextToken;

        Activity::log('inscription', "Nouvel utilisateur : {$user->email}", ['user_id' => $user->id]);

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'token' => $token,
        ], 201);
    }

    /**
     * Log in an existing user.
     *
     * Si le 2FA est activé pour ce compte, aucun token final n'est délivré ici :
     * on renvoie un temp_token de courte durée, à échanger contre le vrai token
     * via POST /login/2fa/verify une fois le code TOTP saisi.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        if ($user->google2fa_enabled) {
            $tempToken = Str::random(64);

            Cache::put(
                "2fa_pending:{$tempToken}",
                $user->id,
                now()->addMinutes(self::TWO_FACTOR_PENDING_TTL)
            );

            return response()->json([
                'two_factor_required' => true,
                'temp_token' => $tempToken,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        Activity::log('connexion', "Connexion de {$user->email}", ['user_id' => $user->id]);

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'token' => $token,
        ]);
    }

    /**
     * POST /login/2fa/verify
     * Deuxième étape du login : vérifie le code TOTP et délivre le vrai token.
     */
    public function verifyTwoFactor(Request $request)
    {
        $validated = $request->validate([
            'temp_token' => 'required|string',
            'code' => 'required|string',
        ]);

        $userId = Cache::get("2fa_pending:{$validated['temp_token']}");

        if (! $userId) {
            throw ValidationException::withMessages([
                'temp_token' => ['Session de connexion expirée. Veuillez vous reconnecter.'],
            ]);
        }

        $user = User::find($userId);

        if (! $user || ! $user->google2fa_enabled || ! $user->google2fa_secret) {
            Cache::forget("2fa_pending:{$validated['temp_token']}");

            throw ValidationException::withMessages([
                'code' => ['Impossible de vérifier ce code. Veuillez vous reconnecter.'],
            ]);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->google2fa_secret, $validated['code']);

        if (! $valid) {
            throw ValidationException::withMessages([
                'code' => ['Code incorrect.'],
            ]);
        }

        // Le temp_token ne sert qu'une seule fois.
        Cache::forget("2fa_pending:{$validated['temp_token']}");

        $token = $user->createToken('auth_token')->plainTextToken;

        Activity::log('connexion', "Connexion de {$user->email} (2FA)", ['user_id' => $user->id]);

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'token' => $token,
        ]);
    }

    /**
     * Log out the current user (revoke current token).
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        if ($user) {
            Activity::log('déconnexion', "Déconnexion de {$user->email}", ['user_id' => $user->id]);
        } else {
            Activity::log('déconnexion', 'Déconnexion d\'un utilisateur non identifié');
        }

        return response()->json([
            'message' => 'Déconnecté avec succès.',
        ]);
    }

    /**
     * Get the authenticated user's info.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
        ]);
    }
}