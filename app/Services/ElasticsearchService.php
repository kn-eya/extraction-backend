<?php

namespace App\Services;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;

class ElasticsearchService
{
    protected string $index;

    public function __construct(
        protected Client $client
    ) {
        $this->index = config('elasticsearch.index', 'companies');
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

        try {
            $this->client->index([
                'index' => $this->index,
                'id'    => $document['id'],
                'body'  => $document,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur indexation Elasticsearch', [
                'id'      => $document['id'],
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Supprimer un document
     */
    public function deleteDocument(string $id): void
    {
        try {
            $this->client->delete([
                'index' => $this->index,
                'id'    => $id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur suppression Elasticsearch', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Recherche Elasticsearch
     */
    public function search(
        array $params,
        int $size = 20,
        int $from = 0,
        ?array $sort = null
    ): array {
        $body = $params;

        if (!isset($body['size'])) {
            $body['size'] = $size;
        }
        if (!isset($body['from'])) {
            $body['from'] = $from;
        }
        if (!isset($body['sort'])) {
            $body['sort'] = $sort ?? ['_score' => ['order' => 'desc']];
        }
        if (!isset($body['track_total_hits'])) {
            $body['track_total_hits'] = 10000;
        }

        try {
            $start = microtime(true);

            // ⚠️ En v8, la réponse est un objet Response, il faut ->asArray()
            $response = $this->client->search([
                'index' => $this->index,
                'body'  => $body,
            ]);

            $duration = microtime(true) - $start;

            $data = $response->asArray();

            Log::info('Elasticsearch HTTP', [
                'duration_seconds' => round($duration, 4),
                'took_ms'          => $data['took'] ?? null,
                'index'            => $this->index,
            ]);

            return $data;

        } catch (\Throwable $e) {
            Log::error('Elasticsearch search failed', [
                'message' => $e->getMessage(),
            ]);

            return [
                'hits' => [
                    'hits'  => [],
                    'total' => ['value' => 0],
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
        try {
            $exists = $this->client->indices()->exists(['index' => $this->index]);

            if ($exists->asBool()) {
                $this->client->indices()->delete(['index' => $this->index]);
            }

            $this->client->indices()->create([
                'index' => $this->index,
                'body'  => $mapping,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur création index Elasticsearch', [
                'index'   => $this->index,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Supprimer l'index
     */
    public function deleteIndex(): void
    {
        try {
            $this->client->indices()->delete(['index' => $this->index]);
        } catch (\Throwable $e) {
            Log::error('Erreur suppression index Elasticsearch', [
                'index'   => $this->index,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Vérifier si l'index existe
     */
    public function indexExists(): bool
    {
        try {
            $response = $this->client->indices()->exists(['index' => $this->index]);
            return $response->asBool();
        } catch (\Throwable $e) {
            Log::warning('Impossible de vérifier l’existence de l’index', [
                'index'   => $this->index,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Bulk index (pour l'indexation massive)
     */
    public function bulkIndex(array $documents): array
    {
        $params = ['body' => []];

        foreach ($documents as $doc) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->index,
                    '_id'    => $doc['id'],
                ],
            ];
            $params['body'][] = $doc;
        }

        try {
            $response = $this->client->bulk($params);
            return $response->asArray();
        } catch (\Throwable $e) {
            Log::error('Erreur bulk index Elasticsearch', [
                'count'   => count($documents),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}