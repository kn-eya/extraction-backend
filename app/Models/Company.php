<?php

namespace App\Models;

use App\Support\BelgiumGeography;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';
    protected $guarded = ['id'];

    protected $casts = [
        'horaires'          => 'array',
        'enrichissement'    => 'array',
        'date_import'       => 'datetime',
        'derniere_verification' => 'datetime',
        'enrichi_le'        => 'datetime',
        'geocode_le'        => 'datetime',
        'es_synchronise_le' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Company $company) {
            $company->region = BelgiumGeography::regionForProvince($company->province);
            $company->est_ouvert = $company->isOpenNow();
        });
    }

    /**
     * Vérifie si l'entreprise est ouverte actuellement
     * Supporte le format : "Mo-Fr 12:00-14:30,18:30-22:00; Sa,Su 12:00-15:30,18:30-22:00"
     */
    public function isOpenNow(): bool
    {
        $horaires = $this->horaires;
        if (empty($horaires)) {
            return false;
        }

        // Si c'est déjà un tableau (casté), on le transforme en chaîne
        if (is_array($horaires)) {
            $horaires = implode('; ', $horaires);
        }

        // Si c'est une chaîne JSON, on la décode (cas où le cast a échoué)
        if (is_string($horaires) && str_starts_with($horaires, '"')) {
            $horaires = json_decode($horaires);
            if (is_array($horaires)) {
                $horaires = implode('; ', $horaires);
            }
        }

        // Maintenant $horaires est une chaîne brute
        if (!is_string($horaires) || trim($horaires) === '') {
            return false;
        }

        $now = now();
        $jourActuel = $now->format('D'); // Mo, Tu, We, Th, Fr, Sa, Su
        $heureActuelle = $now->format('H:i');

        // Séparer les groupes de jours (ex: "Mo-Fr 12:00-14:30,18:30-22:00" et "Sa,Su 12:00-15:30,18:30-22:00")
        $groupes = explode(';', $horaires);

        foreach ($groupes as $groupe) {
            $groupe = trim($groupe);
            if (empty($groupe)) continue;

            // Séparer la partie jours et la partie heures
            if (preg_match('/^([A-Za-z, -]+)\s+(.+)$/', $groupe, $matches)) {
                $jours = $matches[1];   // "Mo-Fr" ou "Sa,Su"
                $times = $matches[2];   // "12:00-14:30,18:30-22:00"

                // Vérifier si le jour actuel correspond à ce groupe
                if ($this->jourCorrespond($jourActuel, $jours)) {
                    // Vérifier si l'heure actuelle est dans l'une des plages horaires
                    if ($this->heureCorrespond($heureActuelle, $times)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Vérifie si l'heure actuelle est dans l'une des plages horaires (ex: "12:00-14:30,18:30-22:00")
     */
    private function heureCorrespond(string $heure, string $plages): bool
    {
        $plagesList = explode(',', $plages);
        foreach ($plagesList as $plage) {
            $plage = trim($plage);
            if (empty($plage)) continue;

            if (preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $plage, $matches)) {
                $debut = $matches[1];
                $fin = $matches[2];
                if ($heure >= $debut && $heure <= $fin) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Vérifie si le jour actuel correspond à une chaîne de jours (ex: "Mo-Fr" ou "Sa,Su")
     */
    private function jourCorrespond(string $jour, string $jours): bool
    {
        $joursList = array_map('trim', explode(',', $jours));
        foreach ($joursList as $j) {
            if (strpos($j, '-') !== false) {
                [$debut, $fin] = explode('-', $j);
                if ($this->estDansIntervalleJour($jour, $debut, $fin)) {
                    return true;
                }
            } else {
                if ($jour === $j) {
                    return true;
                }
            }
        }
        return false;
    }

    private function estDansIntervalleJour(string $jour, string $debut, string $fin): bool
    {
        $joursSemaine = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        $indexJour = array_search($jour, $joursSemaine);
        $indexDebut = array_search($debut, $joursSemaine);
        $indexFin = array_search($fin, $joursSemaine);

        if ($indexDebut === false || $indexFin === false) {
            return false;
        }

        return $indexJour >= $indexDebut && $indexJour <= $indexFin;
    }
}