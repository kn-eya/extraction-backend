<?php

namespace App\Providers;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Support\ServiceProvider;

class ElasticsearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, function () {
            $hosts = config('elasticsearch.hosts', [
                'http://elasticsearch:9200',
            ]);

            return ClientBuilder::create()
                ->setHosts($hosts)
                ->build();
        });
    }

    public function boot(): void
    {
        //
    }
}