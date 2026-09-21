<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class FillContacts extends Command
{
    protected $signature = 'extraction:fill-contacts
        {--province= : Filtrer par province}
        {--region= : Filtrer par région}
        {--force : Force la mise à jour de tous les champs (écrase les valeurs existantes)}';

    protected $description = 'Remplit TOUS les tags OSM (contacts, réseaux, taille, statut, notes) via Overpass avec fallback multi-serveurs et batch de 50';

    // Même liste que TestExtraction – kumi en premier
    private const SERVEURS_OVERPASS = [
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass-api.de/api/interpreter',
        'https://overpass.openstreetmap.ru/api/interpreter',
    ];

    private const TENTATIVES_PAR_SERVEUR = 2;
    private const CODES_TRANSITOIRES = [429, 500, 502, 503, 504];
    private const BATCH_SIZE = 50;

    public function handle(): int
    {
        $query = Company::where(function ($q) {
            $q->whereNull('telephone')->orWhere('telephone', '')
              ->orWhereNull('email')->orWhere('email', '')
              ->orWhereNull('site_web')->orWhere('site_web', '')
              ->orWhereNull('horaires')
              ->orWhereNull('enrichissement')
              ->orWhereNull('taille_entreprise')
              ->orWhereNull('statut')
              ->orWhereNull('note')
              ->orWhereNull('nb_avis');
        })->whereNotNull('osm_id');

        if ($province = $this->option('province')) {
            $query->where('province', $province);
        }
        if ($region = $this->option('region')) {
            $query->where('region', $region);
        }

        $companies = $query->get();
        $this->info("{$companies->count()} entreprise(s) avec des champs manquants.");

        if ($companies->isEmpty()) {
            return 0;
        }

        $batches = $companies->chunk(self::BATCH_SIZE);
        $this->info("Répartition en " . $batches->count() . " lots de " . self::BATCH_SIZE . " entreprises.");

        $bar = $this->output->createProgressBar($companies->count());
        $bar->start();

        $updated = 0;
        $force = $this->option('force');

        foreach ($batches as $batch) {
            $this->traiterLot($batch, $bar, $updated, $force);
            usleep(800000); // 0.8s entre les lots pour éviter le blocage
        }

        $bar->finish();
        $this->newLine();
        $this->info("Mise à jour effectuée pour {$updated} entreprise(s).");
        return 0;
    }

    private function traiterLot($batch, $bar, &$updated, $force): void
    {
        $parts = [];
        foreach ($batch as $c) {
            $type = $c->osm_type ?? 'node';
            $parts[] = "{$type}({$c->osm_id});";
        }
        $queryOverpass = "[out:json];(" . implode('', $parts) . ");out body;";

        $elements = $this->interrogerOverpassAvecRetry($queryOverpass);

        if ($elements === null) {
            $this->warn("  Échec pour ce lot, passage au suivant.");
            $bar->advance(count($batch));
            return;
        }

        $index = [];
        foreach ($elements as $el) {
            $key = $el['type'] . '_' . $el['id'];
            $index[$key] = $el['tags'] ?? [];
        }

        foreach ($batch as $c) {
            $key = ($c->osm_type ?? 'node') . '_' . $c->osm_id;
            $tags = $index[$key] ?? [];

            // ---------- EXTRACTION DE TOUS LES TAGS (identique à TestExtraction) ----------
            // Contacts
            $telephone = $tags['phone'] ?? 
                          $tags['contact:phone'] ?? 
                          $tags['mobile'] ?? 
                          $tags['contact:mobile'] ?? 
                          $tags['phone:1'] ?? 
                          $tags['phone:2'] ?? 
                          $tags['fax'] ?? 
                          null;

            $email = $tags['email'] ?? 
                     $tags['contact:email'] ?? 
                     $tags['mail'] ?? 
                     $tags['contact:mail'] ?? 
                     null;

            $site_web = $tags['website'] ?? 
                        $tags['url'] ?? 
                        $tags['contact:website'] ?? 
                        $tags['web'] ?? 
                        $tags['contact:web'] ?? 
                        $tags['homepage'] ?? 
                        null;

            $horaires = $tags['opening_hours'] ?? 
                        $tags['opening_hours:1'] ?? 
                        $tags['opening_hours:2'] ?? 
                        $tags['service_times'] ?? 
                        null;

            // Réseaux sociaux + enrichissement (JSON)
            $social = [
                'facebook'   => $tags['contact:facebook'] ?? $tags['facebook'] ?? null,
                'instagram'  => $tags['contact:instagram'] ?? $tags['instagram'] ?? null,
                'twitter'    => $tags['contact:twitter'] ?? $tags['twitter'] ?? null,
                'linkedin'   => $tags['contact:linkedin'] ?? $tags['linkedin'] ?? null,
                'youtube'    => $tags['contact:youtube'] ?? $tags['youtube'] ?? null,
                'tiktok'     => $tags['contact:tiktok'] ?? $tags['tiktok'] ?? null,
                'telegram'   => $tags['contact:telegram'] ?? $tags['telegram'] ?? null,
                'whatsapp'   => $tags['contact:whatsapp'] ?? $tags['whatsapp'] ?? null,
                'viber'      => $tags['contact:viber'] ?? $tags['viber'] ?? null,
                'pinterest'  => $tags['contact:pinterest'] ?? $tags['pinterest'] ?? null,
                'snapchat'   => $tags['contact:snapchat'] ?? $tags['snapchat'] ?? null,
                'signal'     => $tags['contact:signal'] ?? $tags['signal'] ?? null,
                'wechat'     => $tags['contact:wechat'] ?? $tags['wechat'] ?? null,
            ];

            // Infos complémentaires
            $social['cuisine'] = $tags['cuisine'] ?? null;
            $social['wheelchair'] = $tags['wheelchair'] ?? null;
            $social['capacity'] = $tags['capacity'] ?? null;
            $social['employees'] = $tags['employees'] ?? null;
            $social['description'] = $tags['description'] ?? $tags['note'] ?? null;
            $social['payment_cash'] = $tags['payment:cash'] ?? null;
            $social['payment_card'] = $tags['payment:cards'] ?? null;
            $social['internet_access'] = $tags['internet_access'] ?? null;
            $social['smoking'] = $tags['smoking'] ?? null;
            $social['dog'] = $tags['dog'] ?? null;
            $social['terrace'] = $tags['terrace'] ?? null;
            $social['outdoor_seating'] = $tags['outdoor_seating'] ?? null;
            $social['takeaway'] = $tags['takeaway'] ?? null;
            $social['delivery'] = $tags['delivery'] ?? null;
            $social['drive_through'] = $tags['drive_through'] ?? null;
            $social['level'] = $tags['level'] ?? null;
            $social['brand'] = $tags['brand'] ?? null;
            $social['operator'] = $tags['operator'] ?? null;
            $social['source'] = $tags['source'] ?? null;

            $social = array_filter($social, fn($v) => !is_null($v) && $v !== '');
            $enrichissement = !empty($social) ? json_encode($social, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;

            // Taille entreprise
            $taille_entreprise = $tags['employees'] ?? $tags['capacity'] ?? $tags['max_employees'] ?? null;

            // Statut
            $statut = 'actif';
            if (isset($tags['disused:shop']) || isset($tags['abandoned:shop']) ||
                isset($tags['disused:amenity']) || isset($tags['abandoned:amenity']) ||
                isset($tags['disused:office']) || isset($tags['abandoned:office']) ||
                isset($tags['disused:leisure']) || isset($tags['abandoned:leisure']) ||
                isset($tags['disused:building']) || isset($tags['abandoned:building']) ||
                isset($tags['disused:landuse']) || isset($tags['abandoned:landuse'])) {
                $statut = 'inactif';
            }
            if (isset($tags['demolished:shop']) || isset($tags['demolished:amenity']) ||
                isset($tags['demolished:building']) || isset($tags['destroyed:building'])) {
                $statut = 'ferme';
            }
            if (isset($tags['construction:shop']) || isset($tags['construction:amenity']) ||
                isset($tags['construction:building'])) {
                $statut = 'en_construction';
            }
            if (isset($tags['proposed:shop']) || isset($tags['proposed:amenity'])) {
                $statut = 'propose';
            }

            // Notes et avis
            $note = isset($tags['rating']) ? (float) $tags['rating'] : null;
            $nb_avis = isset($tags['review_count']) ? (int) $tags['review_count'] : null;

            // ---------- MISE À JOUR ----------
            $dirty = false;

            if ($force || empty($c->telephone)) {
                if ($telephone) { $c->telephone = $telephone; $dirty = true; }
            }
            if ($force || empty($c->email)) {
                if ($email) { $c->email = $email; $dirty = true; }
            }
            if ($force || empty($c->site_web)) {
                if ($site_web) { $c->site_web = $site_web; $dirty = true; }
            }
            if ($force || empty($c->horaires)) {
                if ($horaires) { $c->horaires = $horaires; $dirty = true; }
            }
            if ($force || empty($c->enrichissement)) {
                if ($enrichissement) { $c->enrichissement = $enrichissement; $dirty = true; }
            }
            if ($force || empty($c->taille_entreprise)) {
                if ($taille_entreprise) { $c->taille_entreprise = $taille_entreprise; $dirty = true; }
            }
            // Pour le statut : on ne force que si force est true, sinon on le met uniquement s'il est vide ou 'actif' (pour remplacer par inactif/fermé)
            if ($force || empty($c->statut) || $c->statut === 'actif') {
                if ($statut && $statut !== 'actif') { $c->statut = $statut; $dirty = true; }
            }
            if ($force || $c->note === null) {
                if ($note !== null) { $c->note = $note; $dirty = true; }
            }
            if ($force || $c->nb_avis === null) {
                if ($nb_avis !== null) { $c->nb_avis = $nb_avis; $dirty = true; }
            }

            if ($dirty) {
                $c->save();
                $updated++;
                $this->line(" ✅ {$c->nom} -> téléphone: {$telephone}, email: {$email}, site: {$site_web}, horaires: {$horaires}, enrichissement: " . ($enrichissement ? '✅' : '❌'));
            } else {
                $this->line(" ❌ Aucune nouvelle info pour {$c->nom}");
            }

            $bar->advance();
        }
    }

    private function interrogerOverpassAvecRetry(string $query): ?array
    {
        foreach (self::SERVEURS_OVERPASS as $indexServeur => $url) {
            $hote = parse_url($url, PHP_URL_HOST);

            for ($tentative = 1; $tentative <= self::TENTATIVES_PAR_SERVEUR; $tentative++) {
                if ($indexServeur > 0 || $tentative > 1) {
                    $this->line("  Tentative {$tentative}/" . self::TENTATIVES_PAR_SERVEUR . " sur {$hote}...");
                }

                try {
                    $response = Http::withHeaders([
                            'User-Agent' => 'BelgiumBusinessExtractor/1.0 (+https://github.com/kn-eya/extraction-backend)',
                            'Accept' => '*/*',
                        ])
                        ->asForm()
                        ->timeout(120)
                        ->post($url, ['data' => $query]);
                } catch (ConnectionException $e) {
                    $this->warn("  Connexion impossible vers {$hote} (tentative {$tentative}/" . self::TENTATIVES_PAR_SERVEUR . ') : ' . $e->getMessage());
                    if ($tentative < self::TENTATIVES_PAR_SERVEUR) {
                        sleep(5 * $tentative);
                    }
                    continue;
                }

                if ($response->successful()) {
                    return $response->json('elements', []);
                }

                $statut = $response->status();

                if ($statut === 429) {
                    $delai = 15 * $tentative;
                    $this->warn("  HTTP 429 sur {$hote}, tentative {$tentative}/" . self::TENTATIVES_PAR_SERVEUR . ", nouvel essai dans {$delai}s...");
                    sleep($delai);
                    continue;
                }

                $transitoire = in_array($statut, self::CODES_TRANSITOIRES, true);

                if (! $transitoire) {
                    $this->warn("  Echec Overpass sur {$hote} (statut {$statut}), abandon de ce serveur.");
                    break;
                }

                if ($tentative < self::TENTATIVES_PAR_SERVEUR) {
                    $delai = 5 * $tentative;
                    $this->warn("  Statut {$statut} sur {$hote}, tentative {$tentative}/" . self::TENTATIVES_PAR_SERVEUR . ", nouvel essai dans {$delai}s...");
                    sleep($delai);
                } else {
                    $this->warn("  Statut {$statut} sur {$hote} apres " . self::TENTATIVES_PAR_SERVEUR . ' tentatives, passage au serveur suivant.');
                }
            }
        }

        return null;
    }
}