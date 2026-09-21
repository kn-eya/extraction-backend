<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'datetime',
        'lu' => 'boolean',
    ];

    public $timestamps = true;

    /**
     * Utilisateur destinataire.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Crée une notification et retourne l'instance créée.
     *
     * Anti-doublon : si une notification identique (même user, même type,
     * même titre, même message) a été créée dans les 60 dernières secondes,
     * on ne la recrée pas et on retourne null.
     */
    public static function creer(
        ?int $userId,
        string $type,
        string $titre,
        ?string $message = null
    ): ?self {
        try {
            // 1. Calculer une clé unique basée sur le contenu
            $cleUnique = md5(json_encode([
                'user_id' => $userId,
                'type'    => $type,
                'titre'   => $titre,
                'message' => $message,
            ]));

            // 2. Vérifier si un doublon existe dans les 60 dernières secondes
            $existe = self::where('cle_unique', $cleUnique)
                ->where('date', '>=', now()->subSeconds(60))
                ->exists();

            if ($existe) {
                \Log::info('Notification dupliquée ignorée', [
                    'user_id' => $userId,
                    'type'    => $type,
                    'cle'     => $cleUnique,
                ]);
                return null;
            }

            // 3. Créer la notification avec la clé unique
            return self::create([
                'user_id'    => $userId,
                'type'       => $type,
                'titre'      => $titre,
                'message'    => $message,
                'lu'         => false,
                'date'       => now(),
                'cle_unique' => $cleUnique,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}