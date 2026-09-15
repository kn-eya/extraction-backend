<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\SearchHistory;
use App\Services\ElasticsearchService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ElasticsearchSearchController extends Controller
{
    public function search(
        Request $request,
        ElasticsearchService $es,
        NotificationService $notif
    ) {
        // =========================================================
        // 1. VALIDATION
        // =========================================================

        $validated = $request->validate([
            'secteur'      => 'nullable|string|max:255',
            'mot_cle'      => 'nullable|string|max:255',
            'ville'        => 'nullable|string|max:255',
            'region'       => 'nullable|string|in:Wallonie,Flandre,Bruxelles-Capitale',
            'province'     => 'nullable|string|max:255',
            'code_postal'  => 'nullable|string|max:10',

            'lat'          => 'nullable|numeric|between:-90,90',
            'lon'          => 'nullable|numeric|between:-180,180',
            'rayon'        => 'nullable|numeric|min:1|max:500',

            'page'         => 'nullable|integer|min:1',
            'par_page'     => 'nullable|integer|min:1|max:100',

            'has_website'  => 'nullable|boolean',
            'has_phone'    => 'nullable|boolean',
            'has_email'    => 'nullable|boolean',
            'has_social'   => 'nullable|boolean',
            'ouvert'       => 'nullable|boolean',
        ]);

        // =========================================================
        // 2. NORMALISATION DES VALEURS
        // =========================================================

        // Supprime les espaces inutiles.
        foreach ([
            'secteur',
            'mot_cle',
            'ville',
            'region',
            'province',
            'code_postal'
        ] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        $must = [];
        $filter = [];

        // =========================================================
        // 3. RECHERCHE PAR MOT-CLÉ
        // =========================================================
        //
        // Avant :
        // - multi_match + fuzziness
        // - match catégorie supplémentaire
        //
        // Maintenant :
        // - une seule multi_match
        // - pas de fuzziness par défaut
        //
        // Cela réduit fortement le coût de la recherche.
        // =========================================================

        if (!empty($validated['mot_cle'])) {
            $must[] = [
                'multi_match' => [
                    'query'  => $validated['mot_cle'],
                    'fields' => [
                        'categorie^10',
                        'nom^3',
                        'ville^2',
                        'adresse',
                        'province',
                    ],
                    'type' => 'best_fields',
                ],
            ];
        }

        // =========================================================
        // 4. FILTRES EXACTS
        // =========================================================

        foreach (
            ['ville', 'province', 'region', 'code_postal', 'statut']
            as $field
        ) {
            if (empty($validated[$field])) {
                continue;
            }

            /*
             * term est préférable pour les champs keyword.
             * match_phrase reste utilisé pour les champs text.
             *
             * On conserve ici ton comportement actuel pour éviter
             * de dépendre d'un mapping Elasticsearch non fourni.
             */

            if (in_array($field, [
                'region',
                'code_postal',
                'statut'
            ])) {
                $filter[] = [
                    'term' => [
                        $field => $validated[$field]
                    ]
                ];
            } else {
                $filter[] = [
                    'match_phrase' => [
                        $field => $validated[$field]
                    ]
                ];
            }
        }

        // =========================================================
        // 5. SECTEUR
        // =========================================================
        //
        // Suppression du fuzziness AUTO.
        // Le filtre est maintenant beaucoup moins coûteux.
        // =========================================================

        if (!empty($validated['secteur'])) {
            $filter[] = [
                'match' => [
                    'categorie' => [
                        'query'    => $validated['secteur'],
                        'operator' => 'and',
                    ],
                ],
            ];
        }

        // =========================================================
        // 6. RECHERCHE PAR RAYON
        // =========================================================

        if (
            isset($validated['lat'], $validated['lon'], $validated['rayon'])
            && $validated['lat'] !== null
            && $validated['lon'] !== null
            && $validated['rayon'] !== null
        ) {
            $filter[] = [
                'geo_distance' => [
                    'distance' => $validated['rayon'] . 'km',
                    'location' => [
                        'lat' => (float) $validated['lat'],
                        'lon' => (float) $validated['lon'],
                    ],
                ],
            ];
        }

        // =========================================================
        // 7. FILTRES DE PRÉSENCE
        // =========================================================

        if (!empty($validated['has_website'])) {
            $filter[] = [
                'exists' => [
                    'field' => 'site_web'
                ]
            ];
        }

        if (!empty($validated['has_phone'])) {
            $filter[] = [
                'exists' => [
                    'field' => 'telephone'
                ]
            ];
        }

        if (!empty($validated['has_email'])) {
            $filter[] = [
                'exists' => [
                    'field' => 'email'
                ]
            ];
        }

        if (!empty($validated['has_social'])) {
            $filter[] = [
                'exists' => [
                    'field' => 'reseaux_sociaux'
                ]
            ];
        }

        // =========================================================
        // 8. ENTREPRISE OUVERTE
        // =========================================================

        if (!empty($validated['ouvert'])) {
            $filter[] = [
                'term' => [
                    'est_ouvert' => true
                ]
            ];
        }

        // =========================================================
        // 9. CONSTRUCTION DE LA REQUÊTE
        // =========================================================

        $query = [
            'bool' => [
                'filter' => $filter,
            ],
        ];

        /*
         * S'il y a une recherche textuelle :
         * on ajoute must.
         *
         * Sinon :
         * match_all.
         */

        if (!empty($must)) {
            $query['bool']['must'] = $must;
        } else {
            $query['bool']['must'] = [
                [
                    'match_all' => new \stdClass()
                ]
            ];
        }

        // =========================================================
        // 10. PAGINATION
        // =========================================================

        $perPage = $validated['par_page'] ?? 20;
        $page = $validated['page'] ?? 1;

        $from = ($page - 1) * $perPage;

        // =========================================================
        // 11. PARAMÈTRES ELASTICSEARCH
        // =========================================================

        $params = [
            'size' => $perPage,
            'from' => $from,

            'query' => $query,

            'sort' => [
                '_score' => [
                    'order' => 'desc'
                ]
            ],

            '_source' => [
                'nom',
                'categorie',
                'ville',
                'region',
                'province',
                'code_postal',
                'adresse',
                'telephone',
                'site_web',
                'email',
                'reseaux_sociaux',
                'latitude',
                'longitude',
                'horaires',
                'est_ouvert',
            ],
        ];

        // =========================================================
        // 12. CACHE REDIS
        // =========================================================
        //
        // Même recherche + mêmes filtres + même page
        // => résultat récupéré depuis le cache pendant 5 minutes.
        // =========================================================

        $cacheKey = 'search_' . md5(
            json_encode(
                $validated,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            . '_page_' . $page
            . '_per_page_' . $perPage
        );

        $results = cache()->remember(
            $cacheKey,
            300,
            function () use ($es, $params) {

                // =================================================
                // IMPORTANT :
                // Ne pas envoyer directement $params à Log::info()
                // car Monolog peut essayer de normaliser toute la
                // structure et produire :
                //
                // "Over 9 levels deep, aborting normalization"
                //
                // On convertit donc la requête en JSON.
                // =================================================

                Log::info(
                    'Requête Elasticsearch (live): '
                    . json_encode(
                        $params,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                );

                // Mesure réelle du temps Elasticsearch
                $start = microtime(true);

                $results = $es->search($params);

                $duration = microtime(true) - $start;

                Log::info(
                    'Temps Elasticsearch : '
                    . number_format($duration, 4)
                    . 's'
                );

                // Temps interne Elasticsearch
                if (isset($results['took'])) {
                    Log::info(
                        'Temps interne Elasticsearch (took) : '
                        . $results['took']
                        . ' ms'
                    );
                }

                return $results;
            }
        );

        // =========================================================
        // 13. HISTORIQUE DE RECHERCHE
        // =========================================================

        $historique = SearchHistory::create([
            'user_id' => $request->user()?->id,

            'type_recherche' => 'Entreprise',

            'mot_cle' => $validated['mot_cle'] ?? null,

            'secteur' => $validated['secteur'] ?? null,

            'ville' => $validated['ville'] ?? null,

            'province' =>
                $validated['province']
                ?? $validated['region']
                ?? null,

            'code_postal' => $validated['code_postal'] ?? null,

            'rayon' => $validated['rayon'] ?? null,

            'has_website' =>
                $validated['has_website'] ?? false,

            'has_phone' =>
                $validated['has_phone'] ?? false,

            'has_email' =>
                $validated['has_email'] ?? false,

            'has_social' =>
                $validated['has_social'] ?? false,

            'date' => now(),
        ]);

        // =========================================================
        // 14. JOURNALISATION DE L'ACTIVITÉ
        // =========================================================

        Activity::log(
            'recherche',
            'Recherche : '
                . ($validated['secteur'] ?? 'tous secteurs'),
            [
                'search_history_id' => $historique->id
            ]
        );

        // =========================================================
        // 15. NOTIFICATION
        // =========================================================

        $user = $request->user();

        if ($user) {

            $criteres = [];

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

            if (!empty($validated['has_website'])) {
                $criteres[] = 'avec site web';
            }

            if (!empty($validated['has_phone'])) {
                $criteres[] = 'avec téléphone';
            }

            if (!empty($validated['has_email'])) {
                $criteres[] = 'avec email';
            }

            if (!empty($validated['has_social'])) {
                $criteres[] = 'avec réseaux sociaux';
            }

            if (!empty($validated['ouvert'])) {
                $criteres[] = 'ouverts';
            }

            $details = !empty($criteres)
                ? implode(', ', $criteres)
                : 'sans filtre spécifique';

            $message =
                "Nouvelle recherche de {$user->name} "
                . "({$user->email}) : {$details}.";

            $notif->nouvelleRecherche($message);
        }

        // =========================================================
        // 16. RÉPONSE JSON
        // =========================================================

        return response()->json([
            'success' => true,

            'data' => array_map(
                fn($hit) => $hit['_source'] ?? [],
                $results['hits']['hits'] ?? []
            ),

            'total' =>
                $results['hits']['total']['value'] ?? 0,

            'page' => $page,

            'per_page' => $perPage,

            'took' =>
                $results['took'] ?? 0,
        ]);
    }

    // =============================================================
    // SUGGESTIONS
    // =============================================================

    public function suggestions(
        Request $request,
        ElasticsearchService $es
    ) {
        $q = trim($request->input('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        $params = [
            'index' => 'companies',

            'body' => [
                'suggest' => [
                    'suggestions' => [
                        'text' => $q,

                        'completion' => [
                            'field' => 'suggest',
                            'size' => 10,
                        ],
                    ],
                ],
            ],
        ];

        $response = $es->search($params);

        $suggestions =
            $response['suggest']['suggestions'][0]['options']
            ?? [];

        return response()->json(
            collect($suggestions)->map(
                fn($option) => [
                    'value' =>
                        $option['_source']['nom']
                        ?? $option['text'],

                    'label' =>
                        $option['text'],

                    'type' =>
                        $option['_source']['type']
                        ?? 'entreprise',
                ]
            )->values()
        );
    }

    // =============================================================
    // SUGGESTION SECTEURS
    // =============================================================

    public function suggestSecteurs()
    {
        return cache()->remember(
            'suggest_secteurs',
            3600,
            function () {
                return \App\Models\Company::select('categorie')
                    ->distinct()
                    ->whereNotNull('categorie')
                    ->pluck('categorie');
            }
        );
    }

    // =============================================================
    // SUGGESTION VILLES
    // =============================================================

    public function suggestVilles()
    {
        return cache()->remember(
            'suggest_villes',
            3600,
            function () {
                return \App\Models\Company::select('ville')
                    ->distinct()
                    ->whereNotNull('ville')
                    ->orderBy('ville')
                    ->pluck('ville');
            }
        );
    }

    // =============================================================
    // SUGGESTION PROVINCES
    // =============================================================

    public function suggestProvinces()
    {
        return cache()->remember(
            'suggest_provinces',
            3600,
            function () {
                return \App\Models\Company::select('province')
                    ->distinct()
                    ->whereNotNull('province')
                    ->pluck('province');
            }
        );
    }

    // =============================================================
    // SUGGESTION CODES POSTAUX
    // =============================================================

    public function suggestCodesPostaux()
    {
        return cache()->remember(
            'suggest_codes_postaux',
            3600,
            function () {
                return \App\Models\Company::select('code_postal')
                    ->distinct()
                    ->whereNotNull('code_postal')
                    ->orderBy('code_postal')
                    ->pluck('code_postal');
            }
        );
    }

    // =============================================================
    // SUGGESTION RÉGIONS
    // =============================================================

    public function suggestRegions()
    {
        return cache()->remember(
            'suggest_regions',
            3600,
            function () {
                return \App\Models\Company::select('region')
                    ->distinct()
                    ->whereNotNull('region')
                    ->pluck('region');
            }
        );
    }
}