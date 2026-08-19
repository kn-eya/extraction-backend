<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class ElasticsearchService
{
    protected string $host;
    protected string $index;
    protected int $timeout = 30;

    public function __construct()
    {
        $this->host = config('elasticsearch.hosts')[0] ?? 'elasticsearch:9200';
        $this->index = config('elasticsearch.index', 'companies');
    }

    /**
     * Indexer un document
     */
    public function indexDocument(array $document): void
    {
        $url = "http://{$this->host}/{$this->index}/_doc/{$document['id']}";
        Http::timeout($this->timeout)->put($url, $document);
    }

    /**
     * Supprimer un document
     */
    public function deleteDocument(string $id): void
    {
        $url = "http://{$this->host}/{$this->index}/_doc/{$id}";
        Http::timeout($this->timeout)->delete($url);
    }

    /**
     * Recherche avancée avec paramètres optimisés.
     *
     * $params peut deja contenir 'size', 'from', 'sort', 'query', etc. (cas du
     * controleur qui calcule sa propre pagination). Dans ce cas, ces valeurs
     * sont respectees et NE SONT PLUS ecrasees. Les arguments $size/$from/$sort
     * ne servent que de valeurs par defaut si $params ne les definit pas deja.
     */
    public function search(array $params, int $size = 20, int $from = 0, array $sort = null): array
    {
        $body = $params;

        // Pagination : on ne definit que ce qui manque, sans ecraser ce que
        // l'appelant a deja mis dans $params.
        $body['size'] = $body['size'] ?? $size;
        $body['from'] = $body['from'] ?? $from;

        if (!isset($body['sort'])) {
            $body['sort'] = $sort ?? ['_score' => ['order' => 'desc']];
        }

        // Optimisation : ne pas compter tous les résultats si le total est > 10000
        if (!isset($body['track_total_hits'])) {
            $body['track_total_hits'] = 10000;
        }

        $url = "http://{$this->host}/{$this->index}/_search";

        try {
            $response = Http::timeout($this->timeout)->post($url, $body);
            return $response->json();
        } catch (RequestException $e) {
            // Log d'erreur
            \Log::error('Elasticsearch search failed: ' . $e->getMessage());
            return ['hits' => ['hits' => [], 'total' => ['value' => 0]], 'took' => 0];
        }
    }

    /**
     * Créer l'index avec le mapping
     */
    public function createIndex(array $mapping): void
    {
        $url = "http://{$this->host}/{$this->index}";
        Http::timeout($this->timeout)->put($url, $mapping);
    }

    /**
     * Supprimer l'index
     */
    public function deleteIndex(): void
    {
        $url = "http://{$this->host}/{$this->index}";
        Http::timeout($this->timeout)->delete($url);
    }

    /**
     * Vérifier si l'index existe
     */
    public function indexExists(): bool
    {
        $url = "http://{$this->host}/{$this->index}";
        $response = Http::timeout($this->timeout)->head($url);
        return $response->successful();
    }
}