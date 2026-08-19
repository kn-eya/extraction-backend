<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SearchHistory;
use App\Services\ElasticsearchService;
use Illuminate\Http\Request;

class ElasticsearchSearchController extends Controller
{
    public function search(Request $request, ElasticsearchService $es)
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
            'rayon' => 'nullable|numeric|min:1|max:500',
            'page' => 'nullable|integer|min:1',
            'par_page' => 'nullable|integer|min:1|max:100',
        ]);

        $must = [];
        $filter = [];

        // 1. Mot-clé avec boost fort sur la catégorie
        if (!empty($validated['mot_cle'])) {
            $must[] = [
                'bool' => [
                    'should' => [
                        // Multi-match avec boost sur catégorie
                        [
                            'multi_match' => [
                                'query' => $validated['mot_cle'],
                                'fields' => [
                                    'categorie^10',   // boost 10 sur la catégorie
                                    'nom^3',
                                    'ville^2',
                                    'adresse^1',
                                    'province^1'
                                ],
                                'fuzziness' => 'AUTO',
                            ],
                        ],
                        // Match explicite sur catégorie avec boost encore plus fort
                        [
                            'match' => [
                                'categorie' => [
                                    'query' => $validated['mot_cle'],
                                    'boost' => 20,
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        // 2. Filtres exacts (keyword)
        foreach (['ville', 'province', 'region', 'code_postal', 'statut'] as $field) {
            if (!empty($validated[$field])) {
                $filter[] = ['term' => ["{$field}.keyword" => $validated[$field]]];
            }
        }

        // 3. Filtre secteur (match sur catégorie)
        if (!empty($validated['secteur'])) {
            $filter[] = [
                'match' => [
                    'categorie' => [
                        'query' => $validated['secteur'],
                        'operator' => 'and',
                    ],
                ],
            ];
        }

        // 4. Rayon (géolocalisation)
        if (!empty($validated['lat']) && !empty($validated['lon']) && !empty($validated['rayon'])) {
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

        $query = [
            'bool' => [
                'must' => $must,
                'filter' => $filter,
            ],
        ];

        // Si aucun mot-clé, on force un match_all
        if (empty($must)) {
            $query['bool']['must'] = [['match_all' => new \stdClass()]];
        }

        $perPage = $validated['par_page'] ?? 20;
        $page = $validated['page'] ?? 1;
        $from = ($page - 1) * $perPage;

        $params = [
            'size' => $perPage,
            'from' => $from,
            'query' => $query,
            'sort' => ['_score' => ['order' => 'desc']],
        ];

        $results = $es->search($params);

        // Historique de recherche
        SearchHistory::create([
            'user_id' => $request->user()?->id,
            'type_recherche' => 'Entreprise',
            'mot_cle' => $validated['mot_cle'] ?? null,
            'secteur' => $validated['secteur'] ?? null,
            'ville' => $validated['ville'] ?? null,
            'province' => $validated['province'] ?? $validated['region'] ?? null,
            'code_postal' => $validated['code_postal'] ?? null,
            'rayon' => $validated['rayon'] ?? null,
            // 'resultats' => $results['hits']['total']['value'] ?? 0, // colonne à ajouter si besoin
            'date' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => array_map(fn($hit) => $hit['_source'], $results['hits']['hits'] ?? []),
            'total' => $results['hits']['total']['value'] ?? 0,
            'page' => $page,
            'per_page' => $perPage,
            'took' => $results['took'] ?? 0,
        ]);
    }
}