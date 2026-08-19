<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class CreateElasticsearchIndex extends Command
{
    protected $signature = 'elasticsearch:create-index';
    protected $description = 'Crée l’index Elasticsearch avec analyseur de synonymes pour les entreprises';

    public function handle(ElasticsearchService $es)
    {
        if ($es->indexExists()) {
            if (!$this->confirm("L'index existe déjà. Le supprimer ?")) {
                return 0;
            }
            $es->deleteIndex();
            $this->info('Index supprimé.');
        }

        $mapping = [
            'settings' => [
                'analysis' => [
                    'analyzer' => [
                        'synonym_analyzer' => [
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'synonym_filter']
                        ]
                    ],
                    'filter' => [
                        'synonym_filter' => [
                            'type' => 'synonym',
                            'synonyms' => [
                                // 🔥 LISTE DE SYNONYMES (Français / Néerlandais / Anglais)
                                'avocat, lawyer, attorney',
                                'boulangerie, bakkerij, bakery, bread',
                                'restaurant, resto, eetgelegenheid',
                                'pharmacie, apotheek, pharmacy',
                                'coiffeur, kapper, hairdresser',
                                'garage, car repair, autogarage',
                                'banque, bank, financier',
                                'notaire, notaris, notary',
                                'comptable, accountant, boekhouder',
                                'assurance, verzekering, insurance',
                                'plombier, loodgieter, plumber',
                                'electricien, elektricien, electrician',
                            ]
                        ]
                    ]
                ]
            ],
            'mappings' => [
                'properties' => [
                    'id'          => ['type' => 'integer'],
                    'nom'         => ['type' => 'text', 'analyzer' => 'synonym_analyzer'],
                    'categorie'   => ['type' => 'text', 'analyzer' => 'synonym_analyzer', 'fields' => ['keyword' => ['type' => 'keyword']]],
                    'adresse'     => ['type' => 'text', 'analyzer' => 'synonym_analyzer'],
                    'ville'       => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                    'province'    => ['type' => 'keyword'],
                    'region'      => ['type' => 'keyword'],
                    'code_postal' => ['type' => 'keyword'],
                    'telephone'   => ['type' => 'text'],
                    'email'       => ['type' => 'text'],
                    'site_web'    => ['type' => 'text'],
                    'note'        => ['type' => 'float'],
                    'nb_avis'     => ['type' => 'integer'],
                    'statut'      => ['type' => 'keyword'],
                    'latitude'    => ['type' => 'float'],
                    'longitude'   => ['type' => 'float'],
                    'location'    => ['type' => 'geo_point'],
                    'date_import' => ['type' => 'date'],
                ],
            ],
        ];

        $es->createIndex($mapping);
        $this->info('✅ Index créé avec succès avec l\'analyseur de synonymes.');
    }
}