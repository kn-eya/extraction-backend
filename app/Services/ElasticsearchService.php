<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ElasticsearchService
{
    protected string $host;

    protected string $index;

    protected int $timeout = 30;

    protected int $connectTimeout = 5;

    public function __construct()
    {
        $this->host = rtrim(
            config('elasticsearch.hosts')[0] ?? 'elasticsearch:9200',
            '/'
        );

        $this->index = config(
            'elasticsearch.index',
            'companies'
        );
    }

    /**
     * Indexer un document
     */
    public function indexDocument(array $document): void
    {
        if (!isset($document['id'])) {
            throw new \InvalidArgumentException(
                'Le document Elasticsearch doit contenir un id.'
            );
        }

        $url = "http://{$this->host}/{$this->index}/_doc/{$document['id']}";

        try {
            Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->put($url, $document)
                ->throw();
        } catch (RequestException $e) {
            Log::error(
                'Erreur indexation Elasticsearch',
                [
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Supprimer un document
     */
    public function deleteDocument(string $id): void
    {
        $url = "http://{$this->host}/{$this->index}/_doc/{$id}";

        try {
            Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->delete($url)
                ->throw();
        } catch (RequestException $e) {
            Log::error(
                'Erreur suppression Elasticsearch',
                [
                    'id' => $id,
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Recherche Elasticsearch
     *
     * Le contrôleur peut fournir :
     * - size
     * - from
     * - sort
     * - query
     * - _source
     * - etc.
     *
     * Ces paramètres sont conservés.
     */
    public function search(
        array $params,
        int $size = 20,
        int $from = 0,
        ?array $sort = null
    ): array {
        $body = $params;

        // =========================================================
        // PAGINATION
        // =========================================================

        if (!isset($body['size'])) {
            $body['size'] = $size;
        }

        if (!isset($body['from'])) {
            $body['from'] = $from;
        }

        // =========================================================
        // TRI
        // =========================================================

        if (!isset($body['sort'])) {
            $body['sort'] = $sort ?? [
                '_score' => [
                    'order' => 'desc'
                ]
            ];
        }

        // =========================================================
        // TOTAL DES RÉSULTATS
        // =========================================================
        //
        // On évite un comptage exact inutile pour les très gros
        // volumes.
        //
        // Elasticsearch retournera :
        // - une valeur exacte jusqu'à 10000
        // - relation "gte" au-delà.
        //
        // =========================================================

        if (!isset($body['track_total_hits'])) {
            $body['track_total_hits'] = 10000;
        }

        // =========================================================
        // URL
        // =========================================================

        $url =
            "http://{$this->host}"
            . "/{$this->index}"
            . "/_search";

        try {

            // =====================================================
            // MESURE DU TEMPS HTTP
            // =====================================================

            $start = microtime(true);

            $response = Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($url, $body);

            $duration = microtime(true) - $start;

            Log::info(
                'Elasticsearch HTTP',
                [
                    'duration_seconds' =>
                        round($duration, 4),

                    'status' =>
                        $response->status(),

                    'index' =>
                        $this->index,
                ]
            );

            // =====================================================
            // ERREUR HTTP
            // =====================================================

            $response->throw();

            $data = $response->json();

            // =====================================================
            // VÉRIFICATION DE LA RÉPONSE
            // =====================================================

            if (!is_array($data)) {
                Log::error(
                    'Réponse Elasticsearch invalide',
                    [
                        'response' => $response->body()
                    ]
                );

                return [
                    'hits' => [
                        'hits' => [],
                        'total' => [
                            'value' => 0
                        ],
                    ],
                    'took' => 0,
                ];
            }

            // =====================================================
            // LOG DU TEMPS INTERNE ELASTICSEARCH
            // =====================================================

            if (isset($data['took'])) {
                Log::info(
                    'Elasticsearch took',
                    [
                        'milliseconds' => $data['took']
                    ]
                );
            }

            return $data;

        } catch (RequestException $e) {

            Log::error(
                'Elasticsearch search failed',
                [
                    'url' => $url,
                    'message' => $e->getMessage(),
                    'status' => $e->response?->status(),
                    'response' => $e->response?->body(),
                ]
            );

            return [
                'hits' => [
                    'hits' => [],
                    'total' => [
                        'value' => 0
                    ],
                ],
                'took' => 0,
            ];
        } catch (\Throwable $e) {

            Log::error(
                'Erreur inattendue Elasticsearch',
                [
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]
            );

            return [
                'hits' => [
                    'hits' => [],
                    'total' => [
                        'value' => 0
                    ],
                ],
                'took' => 0,
            ];
        }
    }

    /**
     * Créer l'index avec le mapping
     */
    public function createIndex(array $mapping): void
    {
        $url =
            "http://{$this->host}"
            . "/{$this->index}";

        try {

            Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->put($url, $mapping)
                ->throw();

        } catch (RequestException $e) {

            Log::error(
                'Erreur création index Elasticsearch',
                [
                    'index' => $this->index,
                    'message' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Supprimer l'index
     */
    public function deleteIndex(): void
    {
        $url =
            "http://{$this->host}"
            . "/{$this->index}";

        try {

            Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->delete($url)
                ->throw();

        } catch (RequestException $e) {

            Log::error(
                'Erreur suppression index Elasticsearch',
                [
                    'index' => $this->index,
                    'message' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Vérifier si l'index existe
     */
    public function indexExists(): bool
    {
        $url =
            "http://{$this->host}"
            . "/{$this->index}";

        try {

            $response = Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->head($url);

            return $response->successful();

        } catch (\Throwable $e) {

            Log::warning(
                'Impossible de vérifier l’existence de l’index Elasticsearch',
                [
                    'index' => $this->index,
                    'message' => $e->getMessage(),
                ]
            );

            return false;
        }
    }
}