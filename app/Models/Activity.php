<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'activities';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'datetime',
    ];

    public $timestamps = true;

    /**
     * Enregistre une activite. Ne fait jamais planter l'appelant meme en cas d'echec
     * (le logging ne doit jamais bloquer une action metier reelle).
     */
    public static function log(string $action, ?string $description = null, array $liens = []): void
    {
        try {
            self::create(array_merge([
                'action' => $action,
                'description' => $description,
                'date' => now(),
            ], $liens));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}