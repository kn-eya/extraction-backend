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
     */
    public static function creer(
        ?int $userId,
        string $type,
        string $titre,
        ?string $message = null
    ): ?self {
        try {
            return self::create([
                'user_id' => $userId,
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
                'lu' => false,
                'date' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}