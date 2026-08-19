<?php

return [
    'hosts' => [
        env('ELASTICSEARCH_HOST', 'elasticsearch:9200'),
    ],
    'index' => env('ELASTICSEARCH_INDEX', 'companies'),
];