<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SearchHistory;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class CompanySearchController extends Controller
{
    /**
     * Service de notifications.
     */
    public function __construct(
        private NotificationService $notificationService
    ) {
    }

    /**
     * Recherche des entreprises.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'secteur' => 'nullable|string|max:255',
            'mot_cle' => 'nullable|string|max:255',
            'ville' => 'nullable|string|max:255',
            'region' => 'nullable|string|in:Wallonie,Flandre,Bruxelles-Capitale',
            'province' => 'nullable|string|max:255',
            'code_postal' => 'nullable|string|max:10',
            'lat' => 'nullable|numeric|between:-90,90',
            'lon' => 'nullable|numeric|between:-180,180',
            'rayon' => 'nullable|numeric|min:5|max:100',
            'page' => 'nullable|integer|min:1',
            'par_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Company::query()
            ->where('statut', 'actif');

        /*
         * Filtre secteur
         */
        if (!empty($validated['secteur'])) {
            $query->where(
                'categorie',
                'ilike',
                '%' . $validated['secteur'] . '%'
            );
        }

        /*
         * Filtre mot-clé
         */
        if (!empty($validated['mot_cle'])) {
            $query->where(
                'nom',
                'ilike',
                '%' . $validated['mot_cle'] . '%'
            );
        }

        /*
         * Filtre ville
         */
        if (!empty($validated['ville'])) {
            $query->where(
                'ville',
                'ilike',
                '%' . $validated['ville'] . '%'
            );
        }

        /*
         * Filtre région
         */
        if (!empty($validated['region'])) {
            $query->where(
                'region',
                $validated['region']
            );
        }

        /*
         * Filtre province
         */
        if (!empty($validated['province'])) {
            $query->whereRaw(
                'unaccent(province) ILIKE unaccent(?)',
                ['%' . $validated['province'] . '%']
            );
        }

        /*
         * Filtre code postal
         */
        if (!empty($validated['code_postal'])) {
            $query->where(
                'code_postal',
                $validated['code_postal']
            );
        }

        /*
         * Recherche par rayon géographique
         */
        $rayonActif =
            !empty($validated['lat']) &&
            !empty($validated['lon']) &&
            !empty($validated['rayon']);

        if ($rayonActif) {
            $lat = $validated['lat'];
            $lon = $validated['lon'];
            $rayonKm = $validated['rayon'];

            $distanceExpr = "
                6371 * acos(
                    LEAST(
                        1,
                        GREATEST(
                            -1,
                            cos(radians(?))
                            * cos(radians(latitude))
                            * cos(
                                radians(longitude)
                                - radians(?)
                            )
                            + sin(radians(?))
                            * sin(radians(latitude))
                        )
                    )
                )
            ";

            $query
                ->selectRaw(
                    "companies.*, ({$distanceExpr}) as distance_km",
                    [$lat, $lon, $lat]
                )
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereRaw(
                    "({$distanceExpr}) <= ?",
                    [$lat, $lon, $lat, $rayonKm]
                )
                ->orderBy('distance_km');
        }

        /*
         * Pagination
         */
        $parPage = $validated['par_page'] ?? 20;

        $resultats = $query->paginate($parPage);

        /*
         * Enregistrement de la recherche
         */
        $historique = SearchHistory::create([
            'user_id' => $request->user()?->id,
            'type_recherche' => 'Entreprise',
            'mot_cle' => $validated['mot_cle'] ?? null,
            'secteur' => $validated['secteur'] ?? null,
            'ville' => $validated['ville'] ?? null,
            'province' => $validated['province']
                ?? $validated['region']
                ?? null,
            'code_postal' => $validated['code_postal'] ?? null,
            'rayon' => $validated['rayon'] ?? null,
            'date' => now(),
        ]);

        /*
         * ==========================================================
         * NOTIFICATION : NOUVELLE RECHERCHE
         * ==========================================================
         */

        $user = $request->user();

        $criteres = [];

        if (!empty($validated['mot_cle'])) {
            $criteres[] =
                'mot-clé "' . $validated['mot_cle'] . '"';
        }

        if (!empty($validated['secteur'])) {
            $criteres[] =
                'secteur "' . $validated['secteur'] . '"';
        }

        if (!empty($validated['ville'])) {
            $criteres[] =
                'ville "' . $validated['ville'] . '"';
        }

        if (!empty($validated['region'])) {
            $criteres[] =
                'région "' . $validated['region'] . '"';
        }

        if (!empty($validated['province'])) {
            $criteres[] =
                'province "' . $validated['province'] . '"';
        }

        if (!empty($validated['code_postal'])) {
            $criteres[] =
                'code postal "' . $validated['code_postal'] . '"';
        }

        if (!empty($validated['rayon'])) {
            $criteres[] =
                'rayon ' . $validated['rayon'] . ' km';
        }

        $detailsRecherche = !empty($criteres)
            ? implode(', ', $criteres)
            : 'sans filtre spécifique';

        /*
         * Identification de l'utilisateur.
         */
        if ($user) {
            $nomUtilisateur = $user->name ?? 'Utilisateur';
            $emailUtilisateur = $user->email ?? 'email non disponible';

            $identiteUtilisateur =
                $nomUtilisateur .
                ' (' .
                $emailUtilisateur .
                ')';
        } else {
            $identiteUtilisateur = 'Utilisateur non authentifié';
        }

        /*
         * Envoi de la notification aux administrateurs.
         */
        $this->notificationService->nouvelleRecherche(
            "Une nouvelle recherche a été effectuée par "
            . $identiteUtilisateur
            . " : "
            . $detailsRecherche
            . "."
        );

        /*
         * Enregistrement de l'activité.
         */
        \App\Models\Activity::log(
            'recherche',
            "Recherche : "
            . ($validated['secteur'] ?? 'tous secteurs'),
            [
                'user_id' => $request->user()?->id,
                'search_history_id' => $historique->id,
            ]
        );

        /*
         * Réponse API.
         */
        return response()->json($resultats);
    }
}