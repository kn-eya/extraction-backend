<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class IndexAllCompanies extends Command
{
    protected $signature = 'elasticsearch:index-all';
    protected $description = 'Indexe toutes les entreprises dans Elasticsearch';

    public function handle(ElasticsearchService $es)
    {
        $total = Company::count();
        if ($total === 0) {
            $this->warn('Aucune entreprise à indexer.');
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Company::chunk(100, function ($companies) use ($es, $bar) {
            foreach ($companies as $company) {
                $es->indexDocument($this->formatDocument($company));
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("✅ Indexation terminée : {$total} entreprises.");
        return 0;
    }

    private function formatDocument(Company $c): array
    {
        return [
            'id'          => $c->id,
            'nom'         => $c->nom,
            'categorie'   => $c->categorie,
            'adresse'     => $c->adresse,
            'ville'       => $c->ville,
            'province'    => $c->province,
            'region'      => $c->region,
            'code_postal' => $c->code_postal,
            'telephone'   => $c->telephone,
            'email'       => $c->email,
            'site_web'    => $c->site_web,
            'note'        => (float) $c->note,
            'nb_avis'     => (int) $c->nb_avis,
            'statut'      => $c->statut,
            'latitude'    => (float) $c->latitude,
            'longitude'   => (float) $c->longitude,
            'location'    => ['lat' => (float) $c->latitude, 'lon' => (float) $c->longitude],
            'date_import' => $c->date_import?->toISOString(),
            // Nouveaux champs pour "ouvert maintenant"
            'horaires'    => $c->horaires,
            'est_ouvert'  => (bool) ($c->est_ouvert ?? false),
        ];
    }
}