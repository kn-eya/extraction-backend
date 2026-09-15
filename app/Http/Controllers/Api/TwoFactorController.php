<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    /**
     * POST /2fa/enable
     * Génère un nouveau secret TOTP (pas encore activé) et retourne
     * l'URL otpauth:// à encoder en QR code côté frontend.
     */
    public function enable(Request $request)
    {
        $user = $request->user();

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        // Le secret est stocké mais le 2FA n'est PAS encore actif :
        // l'activation ne se fait qu'après confirmation d'un code valide (confirm()).
        $user->forceFill([
            'google2fa_secret' => $secret,
            'google2fa_enabled' => false,
        ])->save();

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name', 'Extraction'),
            $user->email,
            $secret
        );

        return response()->json([
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
        ]);
    }

    /**
     * POST /2fa/confirm
     * Vérifie le premier code saisi par l'utilisateur et active définitivement le 2FA.
     */
    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();

        if (! $user->google2fa_secret) {
            throw ValidationException::withMessages([
                'code' => ["Aucune configuration 2FA en attente. Démarrez l'activation d'abord."],
            ]);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->google2fa_secret, $validated['code']);

        if (! $valid) {
            throw ValidationException::withMessages([
                'code' => ['Le code saisi est incorrect ou a expiré.'],
            ]);
        }

        $user->forceFill([
            'google2fa_enabled' => true,
            'google2fa_confirmed_at' => now(),
        ])->save();

        Activity::log('2fa_active', "Double authentification activée pour {$user->email}", ['user_id' => $user->id]);

        return response()->json([
            'message' => 'Double authentification activée avec succès.',
        ]);
    }

    /**
     * POST /2fa/disable
     * Désactive le 2FA. Exige le mot de passe actuel pour confirmer.
     */
    public function disable(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Mot de passe incorrect.'],
            ]);
        }

        $user->forceFill([
            'google2fa_secret' => null,
            'google2fa_enabled' => false,
            'google2fa_confirmed_at' => null,
        ])->save();

        Activity::log('2fa_desactive', "Double authentification désactivée pour {$user->email}", ['user_id' => $user->id]);

        return response()->json([
            'message' => 'Double authentification désactivée.',
        ]);
    }

    /**
     * GET /2fa/status
     * Indique si le 2FA est actif pour l'utilisateur connecté.
     */
    public function status(Request $request)
    {
        return response()->json([
            'enabled' => (bool) $request->user()->google2fa_enabled,
        ]);
    }
}