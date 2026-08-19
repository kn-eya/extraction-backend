<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FillPhone extends Command
{
    protected $signature = 'extraction:fill-phone
        {--province= : Filtrer par province}
        {--region= : Filtrer par région}';

    protected $description = 'Remplit le téléphone des entreprises via Overpass (osm_id) sans toucher aux autres champs';

    public function handle(): int
    {
        $query = Company::where(function ($q) {
                $q->whereNull('telephone')->orWhere('telephone', '');
            })
            ->whereNotNull('osm_id');

        if ($province = $this->option('province')) {
            $query->where('province', $province);
        }
        if ($region = $this->option('region')) {
            $query->where('region', $region);
        }

        $companies = $query->get();
        $this->info("{$companies->count()} entreprise(s) sans téléphone.");

        if ($companies->isEmpty()) {
            return 0;
        }

        $bar = $this->output->createProgressBar(count($companies));
        $bar->start();

        foreach ($companies as $c) {
            $query = <<<OVERPASS
                [out:json];
                node({$c->osm_id});
                out body;
            OVERPASS;

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'BelgiumBusinessExtractor/1.0 (contact: 3lmsolutions@gmail.com)',
                    'Accept' => '*/*',
                ])->asForm()
                  ->timeout(30)
                  ->post('https://overpass-api.de/api/interpreter', ['data' => $query]);

                if ($response->successful()) {
                    $data = $response->json();
                    $elements = $data['elements'] ?? [];
                    if (!empty($elements) && isset($elements[0]['tags'])) {
                        $tags = $elements[0]['tags'];
                        $phone = $tags['phone'] ?? $tags['contact:phone'] ?? $tags['mobile'] ?? null;

                        if ($phone) {
                            $c->telephone = $phone;
                            $c->save();
                            $this->line(" ✅ {$c->nom} -> {$phone}");
                        } else {
                            $this->line(" ❌ Aucun téléphone trouvé pour {$c->nom}");
                        }
                    } else {
                        $this->line(" ❌ Aucun élément Overpass pour {$c->nom}");
                    }
                } else {
                    $this->error("Erreur HTTP pour osm_id {$c->osm_id} (statut {$response->status()})");
                }
            } catch (\Exception $e) {
                $this->error("Erreur pour {$c->nom} : " . $e->getMessage());
            }

            // Pause pour ne pas surcharger Overpass
            usleep(500000); // 0.5s
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Terminé.');
        return 0;
    }
}