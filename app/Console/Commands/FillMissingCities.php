<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class FillMissingCities extends Command
{
    protected $signature = 'extraction:fill-cities
        {--province= : Filtrer par province}
        {--limit=20 : Nombre a traiter (pour tester d\'abord en petit)}';

    protected $description = 'Remplit province/region via Photon (komoot), alternative gratuite a Nominatim.';

    private const PROVINCE_MAP = [
        'Anvers' => 'Anvers', "Province d'Anvers" => 'Anvers',
        'Brabant flamand' => 'Brabant flamand', 'Province du Brabant flamand' => 'Brabant flamand',
        'Flandre-Occidentale' => 'Flandre-Occidentale', 'Province de Flandre-Occidentale' => 'Flandre-Occidentale',
        'Flandre-Orientale' => 'Flandre-Orientale', 'Province de Flandre-Orientale' => 'Flandre-Orientale',
        'Limbourg' => 'Limbourg', 'Province de Limbourg' => 'Limbourg',
        'Brabant wallon' => 'Brabant wallon', 'Province du Brabant wallon' => 'Brabant wallon',
        'Hainaut' => 'Hainaut', 'Province de Hainaut' => 'Hainaut',
        'Liège' => 'Liege', 'Province de Liège' => 'Liege', 'Liege' => 'Liege',
        'Luxembourg' => 'Luxembourg', 'Province de Luxembourg' => 'Luxembourg',
        'Namur' => 'Namur', 'Province de Namur' => 'Namur',
        'Bruxelles-Capitale' => 'Bruxelles-Capitale', 'Région de Bruxelles-Capitale' => 'Bruxelles-Capitale',
        'Brussels' => 'Bruxelles-Capitale', 'Brussels-Capital Region' => 'Bruxelles-Capitale',
    ];

    public function handle(): int
    {
        $query = Company::where(function ($q) {
                $q->whereNull('province')->orWhere('province', '');
            })
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($province = $this->option('province')) {
            $query->where('province', $province);
        }

        $limit = (int) $this->option('limit');
        $companies = $query->limit($limit)->get();

        $this->info("{$companies->count()} entreprise(s) a traiter (limite: {$limit}).");

        if ($companies->isEmpty()) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($companies->count());
        $bar->start();

        $updated = 0;

        foreach ($companies as $company) {
            try {
                $response = Http::withHeaders([
                        'User-Agent' => 'BelgiumBusinessExtractor/1.0 (+https://github.com/kn-eya/extraction-backend)',
                    ])
                    ->timeout(15)
                    ->get('https://photon.komoot.io/reverse', [
                        'lat' => $company->latitude,
                        'lon' => $company->longitude,
                        'lang' => 'fr',
                    ]);
            } catch (ConnectionException $e) {
                $this->warn("  Connexion impossible pour {$company->nom} : " . $e->getMessage());
                $bar->advance();
                continue;
            }

            if ($response->failed()) {
                $this->warn("  Echec pour {$company->nom} (statut " . $response->status() . ')');
                $bar->advance();
                continue;
            }

            $data = $response->json();
            $props = $data['features'][0]['properties'] ?? null;

            if (! $props) {
                $this->line(" ❌ Aucun resultat pour {$company->nom}");
                $bar->advance();
                usleep(300000);
                continue;
            }

            // DEBUG : on affiche tout ce que Photon renvoie pour ajuster le mapping si besoin
            $this->line(' 🔎 ' . $company->nom . ' -> ' . json_encode($props, JSON_UNESCAPED_UNICODE));

            if (($props['countrycode'] ?? null) !== 'BE') {
    $this->warn(" 🗑️  {$company->nom} confirme hors Belgique (pays: " . ($props['country'] ?? '?') . "), suppression.");
    $company->delete();
    $bar->advance();
    usleep(300000);
    continue;
}

$ville = $props['city'] ?? $props['town'] ?? $props['village'] ?? null;
            $provinceBrute = $props['state'] ?? null;
            $province = $provinceBrute ? (self::PROVINCE_MAP[$provinceBrute] ?? null) : null;
            $cp = $props['postcode'] ?? null;

            $dirty = false;
            if ($ville && empty($company->ville)) { $company->ville = $ville; $dirty = true; }
            if ($province) { $company->province = $province; $dirty = true; }
            if ($cp && empty($company->code_postal)) { $company->code_postal = $cp; $dirty = true; }

            if ($dirty) {
                $company->geocode_source = 'photon';
                $company->geocode_le = now();
                $company->save(); // declenche saving() -> region recalculee
                $updated++;
                $this->line(" ✅ {$company->nom} -> {$ville} / {$province} (region: {$company->region})");
            }

            $bar->advance();
            usleep(300000); // 0.3s entre chaque appel, usage raisonnable
        }

        $bar->finish();
        $this->newLine();
        $this->info("{$updated} entreprise(s) mise(s) a jour.");

        return self::SUCCESS;
    }
}