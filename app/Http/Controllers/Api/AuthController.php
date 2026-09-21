<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
        $user->load('roles'); // Eager loading après assignation

        $token = $user->createToken('auth_token')->plainTextToken;

        // Activity log en afterResponse (non bloquant)
        $this->logActivity(
            'inscription',
            "Nouvel utilisateur : {$user->email}",
            ['user_id' => $user->id]
        );

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'token' => $token,
        ], 201);
    }

    /**
     * Log in an existing user.
     */
    public function login(Request $request)
    {
        $t0 = microtime(true);

        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $t1 = microtime(true);

        // ✅ Eager loading des rôles pour éviter les N+1
        $user = User::with('roles')->where('email', $validated['email'])->first();

        $t2 = microtime(true);

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        $t3 = microtime(true);

        // 2FA activé → on renvoie un temp_token
        if ($user->google2fa_enabled) {
            $tempToken = Str::random(64);

            Cache::put(
                "2fa_pending:{$tempToken}",
                $user->id,
                now()->addMinutes(self::TWO_FACTOR_PENDING_TTL)
            );

            $this->logTimings('login (2FA pending)', [
                'validation' => $t1 - $t0,
                'user_query' => $t2 - $t1,
                'hash_check' => $t3 - $t2,
                'total'      => microtime(true) - $t0,
            ]);

            return response()->json([
                'two_factor_required' => true,
                'temp_token' => $tempToken,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $t4 = microtime(true);

        // ✅ Activity log en afterResponse (non bloquant)
        $this->logActivity(
            'connexion',
            "Connexion de {$user->email}",
            ['user_id' => $user->id]
        );

        $this->logTimings('login', [
            'validation'   => $t1 - $t0,
            'user_query'   => $t2 - $t1,
            'hash_check'   => $t3 - $t2,
            'token_create' => $t4 - $t3,
            'total'        => microtime(true) - $t0,
        ]);

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'token' => $token,
        ]);
    }

    /**
     * POST /login/2fa/verify
     */
    public function verifyTwoFactor(Request $request)
    {
        $t0 = microtime(true);

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

        // ✅ Eager loading des rôles
        $user = User::with('roles')->find($userId);

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

        $this->logActivity(
            'connexion',
            "Connexion de {$user->email} (2FA)",
            ['user_id' => $user->id]
        );

        $this->logTimings('login (2FA verify)', [
            'total' => microtime(true) - $t0,
        ]);

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'token' => $token,
        ]);
    }

    /**
     * Log out the current user.
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        if ($user) {
            $this->logActivity(
                'déconnexion',
                "Déconnexion de {$user->email}",
                ['user_id' => $user->id]
            );
        } else {
            $this->logActivity(
                'déconnexion',
                "Déconnexion d'un utilisateur non identifié"
            );
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
        // ✅ Eager loading des rôles
        $user = $request->user()->load('roles');

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
        ]);
    }

    /**
     * Enregistre une activité en afterResponse (non bloquant).
     */
    private function logActivity(string $type, string $message, array $context = []): void
    {
        dispatch(function () use ($type, $message, $context) {
            try {
                Activity::log($type, $message, $context);
            } catch (\Throwable $e) {
                Log::warning('Erreur Activity::log', [
                    'type'    => $type,
                    'message' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    /**
     * Logue les temps d'exécution du login.
     */
    private function logTimings(string $label, array $timings): void
    {
        $formatted = [];
        foreach ($timings as $key => $value) {
            $formatted[$key] = round($value * 1000, 2) . 'ms';
        }

        Log::info("Login timings [{$label}]", $formatted);
    }
}