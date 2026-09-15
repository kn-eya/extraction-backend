<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(HttpRequest $request)
    {
        // 1. Total entreprises actives
        $totalEntreprises = Company::where('statut', 'actif')->count();

        // 2. Répartition par région
        $parRegion = Company::where('statut', 'actif')
            ->whereNotNull('region')
            ->select('region', DB::raw('count(*) as total'))
            ->groupBy('region')
            ->orderBy('total', 'desc')
            ->get();

        // 3. Répartition par province (top 20)
        $parProvince = Company::where('statut', 'actif')
            ->whereNotNull('province')
            ->select('province', DB::raw('count(*) as total'))
            ->groupBy('province')
            ->orderBy('total', 'desc')
            ->limit(20)
            ->get();

        // 4. Top 20 villes
        $parVille = Company::where('statut', 'actif')
            ->whereNotNull('ville')
            ->select('ville', DB::raw('count(*) as total'))
            ->groupBy('ville')
            ->orderBy('total', 'desc')
            ->limit(20)
            ->get();

        // 5. Top 20 catégories
        $parCategorie = Company::where('statut', 'actif')
            ->whereNotNull('categorie')
            ->select('categorie', DB::raw('count(*) as total'))
            ->groupBy('categorie')
            ->orderBy('total', 'desc')
            ->limit(20)
            ->get();

        // 6. Évolution des imports (30 derniers jours)
        $evolution = Company::where('statut', 'actif')
            ->where('date_import', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(date_import) as jour'), DB::raw('count(*) as total'))
            ->groupBy('jour')
            ->orderBy('jour', 'asc')
            ->get();

        // 7. Nouveaux contacts (email dans les 7 derniers jours)
        $nouveauxContacts = Company::where('statut', 'actif')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('date_import', '>=', now()->subDays(7))
            ->count();

        // 8. Données pour cartographie (limité à 5000)
        $cartes = Company::where('statut', 'actif')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('nom', 'categorie', 'ville', 'province', 'region', 'latitude', 'longitude')
            ->limit(5000)
            ->get();

        // 9. Stats complémentaires
        $statsComplementaires = [
            'avec_telephone' => Company::where('statut', 'actif')
                ->whereNotNull('telephone')
                ->where('telephone', '!=', '')
                ->count(),
            'sans_telephone' => Company::where('statut', 'actif')
                ->where(function ($q) {
                    $q->whereNull('telephone')->orWhere('telephone', '');
                })
                ->count(),
            'avec_email' => Company::where('statut', 'actif')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->count(),
            'sans_email' => Company::where('statut', 'actif')
                ->where(function ($q) {
                    $q->whereNull('email')->orWhere('email', '');
                })
                ->count(),
            'avec_site_web' => Company::where('statut', 'actif')
                ->whereNotNull('site_web')
                ->where('site_web', '!=', '')
                ->count(),
            'sans_site_web' => Company::where('statut', 'actif')
                ->where(function ($q) {
                    $q->whereNull('site_web')->orWhere('site_web', '');
                })
                ->count(),
            'avec_horaires' => Company::where('statut', 'actif')
                ->whereNotNull('horaires')
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'total_entreprises' => $totalEntreprises,
                'par_region' => $parRegion,
                'par_province' => $parProvince,
                'par_ville' => $parVille,
                'par_categorie' => $parCategorie,
                'evolution_imports' => $evolution,
                'nouveaux_contacts_7j' => $nouveauxContacts,
                'cartes' => $cartes,
                'stats_complementaires' => $statsComplementaires,
            ],
        ]);
    }

    /**
     * Statistiques avancées
     * - entreprises par secteur
     * - entreprises par province
     * - avec/sans site
     * - sans téléphone
     * - sans email
     */
    public function stats()
    {
        $total = Company::where('statut', 'actif')->count();

        // 1. Par secteur (top 20)
        $parSecteur = Company::where('statut', 'actif')
            ->whereNotNull('categorie')
            ->select('categorie', DB::raw('count(*) as total'))
            ->groupBy('categorie')
            ->orderBy('total', 'desc')
            ->limit(20)
            ->get();

        // 2. Par province
        $parProvince = Company::where('statut', 'actif')
            ->whereNotNull('province')
            ->select('province', DB::raw('count(*) as total'))
            ->groupBy('province')
            ->orderBy('total', 'desc')
            ->get();

        // 3. Avec / sans site web
        $avecSite = Company::where('statut', 'actif')
            ->whereNotNull('site_web')
            ->where('site_web', '!=', '')
            ->count();
        $sansSite = $total - $avecSite;

        // 4. Sans téléphone
        $sansTel = Company::where('statut', 'actif')
            ->where(function ($q) {
                $q->whereNull('telephone')->orWhere('telephone', '');
            })
            ->count();

        // 5. Sans email
        $sansEmail = Company::where('statut', 'actif')
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '');
            })
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'par_secteur' => $parSecteur,
                'par_province' => $parProvince,
                'avec_site' => $avecSite,
                'sans_site' => $sansSite,
                'sans_telephone' => $sansTel,
                'sans_email' => $sansEmail,
            ]
        ]);
    }
}