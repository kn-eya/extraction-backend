<?php
namespace App\Console\Commands;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use App\Models\Activity;
use App\Services\NotificationService;
class TestExtraction extends Command
{
    protected $signature = 'extraction:test
        {--bbox=49.50,2.50,51.50,6.50 : sud,ouest,nord,est (defaut: Belgique entiere)}
        {--seul= : tester un seul secteur, ex: office=lawyer}
        {--sans-nominatim : desactive le geocodage Nominatim (extraction Overpass seule, plus rapide)}
        {--force : force la mise à jour de tous les champs (écrase les valeurs existantes)}';

    protected $description = 'Extraction multi-secteurs avec récupération de TOUS les tags OSM (contacts, réseaux, horaires, taille, statut, notes)';

private const SERVEURS_OVERPASS = [
    'https://overpass.kumi.systems/api/interpreter',    // ✅ le seul qui répond en ce moment
    'https://overpass-api.de/api/interpreter',          // en backup
    'https://overpass.openstreetmap.ru/api/interpreter', // dernier recours
];
    private const TENTATIVES_PAR_SERVEUR = 2; // on tente 2 fois par serveur puis on bascule
    private const CODES_TRANSITOIRES = [429, 500, 502, 503, 504];

    private const SECTEURS = [
        'amenity=restaurant' => 'Restaurant',
        'amenity=cafe' => 'Cafe',
        'amenity=fast_food' => 'Restauration rapide',
        'amenity=bar' => 'Bar',
        'amenity=pharmacy' => 'Pharmacie',
        'amenity=dentist' => 'Dentiste',
        'amenity=doctors' => 'Medecin',
        'amenity=veterinary' => 'Veterinaire',
        'shop=supermarket' => 'Supermarche',
        'shop=bakery' => 'Boulangerie',
        'shop=butcher' => 'Boucherie',
        'shop=clothes' => 'Vetements',
        'shop=hairdresser' => 'Coiffeur',
        'shop=car' => 'Concessionnaire auto',
        'shop=furniture' => 'Meubles',
        'shop=electronics' => 'Electronique',
        'office=lawyer' => 'Avocat',
        'office=estate_agent' => 'Agence immobiliere',
        'office=it' => 'Informatique',
        'office=accountant' => 'Comptable',
        'office=insurance' => 'Assurance',
        'office=notary' => 'Notaire',
        'office=tax_advisor' => 'Conseiller fiscal',
        'office=consulting' => 'Conseil (consulting)',
        'office=advertising_agency' => 'Agence de publicite',
        'office=architect' => 'Architecte',
        'office=engineer' => "Bureau d'ingenierie",
        'office=company' => "Siege d'entreprise",
        'office=financial' => 'Services financiers',
        'office=logistics' => 'Logistique',
        'office=telecommunication' => 'Telecommunications',
        'shop=trade' => 'Commerce de gros',
        'craft=plumber' => 'Plombier',
        'craft=electrician' => 'Electricien',
        'craft=carpenter' => 'Menuisier',
        'craft=painter' => 'Peintre en batiment',
        'craft=metal_construction' => 'Construction metallique',
        'shop=car_repair' => 'Garage auto',
        'amenity=bank' => 'Banque',
    ];

    private const NOMINATIM_PROVINCE_MAP = [
        'Anvers' => 'Anvers',
        "Province d'Anvers" => 'Anvers',
        'Brabant flamand' => 'Brabant flamand',
        'Province du Brabant flamand' => 'Brabant flamand',
        'Flandre-Occidentale' => 'Flandre-Occidentale',
        'Province de Flandre-Occidentale' => 'Flandre-Occidentale',
        'Flandre-Orientale' => 'Flandre-Orientale',
        'Province de Flandre-Orientale' => 'Flandre-Orientale',
        'Limbourg' => 'Limbourg',
        'Province de Limbourg' => 'Limbourg',
        'Brabant wallon' => 'Brabant wallon',
        'Province du Brabant wallon' => 'Brabant wallon',
        'Hainaut' => 'Hainaut',
        'Province de Hainaut' => 'Hainaut',
        'Liège' => 'Liege',
        'Province de Liège' => 'Liege',
        'Luxembourg' => 'Luxembourg',
        'Province de Luxembourg' => 'Luxembourg',
        'Namur' => 'Namur',
        'Province de Namur' => 'Namur',
        'Bruxelles-Capitale' => 'Bruxelles-Capitale',
        'Région de Bruxelles-Capitale' => 'Bruxelles-Capitale',
        'Brussels' => 'Bruxelles-Capitale',
    ];
public function handle(NotificationService $notifications): int
    {
        set_time_limit(0);
    

        [$sud, $ouest, $nord, $est] = explode(',', $this->option('bbox'));
        $avecNominatim = ! $this->option('sans-nominatim');
        $force = $this->option('force');

        $secteurs = self::SECTEURS;
        if ($this->option('seul')) {
            $tag = $this->option('seul');
            $secteurs = [$tag => self::SECTEURS[$tag] ?? $tag];
        }

        $totalNouveaux = 0;
        $totalMisAJour = 0;
        $totalGeocodes = 0;
        $totalEchecsGeocodage = 0;

        foreach ($secteurs as $tag => $categorieLisible) {
            [$cle, $valeur] = explode('=', $tag);

            $this->info("Secteur : {$categorieLisible} ({$tag})...");

            $query = <<<OVERPASS
                [out:json][timeout:180];
                area["ISO3166-1"="BE"][admin_level=2]->.belgique;
                node["{$cle}"="{$valeur}"](area.belgique);
                out body;
            OVERPASS;

            $elements = $this->interrogerOverpassAvecRetry($query, $tag);

            if ($elements === null) {
                $this->error("  Tous les serveurs Overpass ont echoue pour {$tag}, secteur ignore.");
                continue;
            }

            $this->line('  ' . count($elements) . ' resultat(s) trouve(s).');

            [$nouveaux, $misAJour, $geocodes, $echecs] = $this->importer($elements, $categorieLisible, $avecNominatim, $force);
            $totalNouveaux += $nouveaux;
            $totalMisAJour += $misAJour;
            $totalGeocodes += $geocodes;
            $totalEchecsGeocodage += $echecs;

            // Pause de 3 secondes entre les secteurs pour respecter les limites
            sleep(3);
        }

        $this->newLine();
        $this->info("TOTAL - Nouvelles entreprises inserees : {$totalNouveaux}");
        $this->info("TOTAL - Entreprises deja connues (dedup, mises a jour) : {$totalMisAJour}");
        $this->info("TOTAL - Geocodees via Nominatim (ville/province/cp completees) : {$totalGeocodes}");
        if ($totalEchecsGeocodage > 0) {
            $this->warn("TOTAL - Echecs de geocodage (reseau, a reessayer plus tard) : {$totalEchecsGeocodage}");
        }
Activity::log(
            'import',
            "Import OSM : {$totalNouveaux} nouveaux, {$totalMisAJour} mis a jour, {$totalGeocodes} geocodes sur " . count($secteurs) . ' secteur(s)',
            [
                'user_id' => null, // commande CLI, pas d'utilisateur authentifie
            ]
        );

        $notifications->importTermine(
            "{$totalNouveaux} nouvelles entreprises, {$totalMisAJour} mises a jour, {$totalGeocodes} geocodees sur " . count($secteurs) . ' secteur(s).'
        );

        if ($totalNouveaux > 0) {
            $notifications->nouveauxContacts(
                "{$totalNouveaux} nouveaux contacts ajoutes lors du dernier import."
            );
        }

        return self::SUCCESS;
    }
        

    private function interrogerOverpassAvecRetry(string $query, string $tag): ?array
    {
        foreach (self::SERVEURS_OVERPASS as $indexServeur => $url) {
            $hote = parse_url($url, PHP_URL_HOST);

            for ($tentative = 1; $tentative <= self::TENTATIVES_PAR_SERVEUR; $tentative++) {
                if ($indexServeur > 0 || $tentative > 1) {
                    $this->line("  Tentative {$tentative}/" . self::TENTATIVES_PAR_SERVEUR . " sur {$hote}...");
                }

                try {
                    $response = Http::withHeaders([
                            'User-Agent' => 'BelgiumBusinessExtractor/1.0 (contact: 3lmsolutions@gmail.com)',
                            'Accept' => '*/*',
                        ])
                        ->asForm()
                        ->timeout(180)
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

                // Gestion spéciale du 429 (Too Many Requests)
                if ($statut === 429) {
                    $delai = 15 * $tentative; // 15s, 30s, 45s
                    $this->warn("  HTTP 429 (trop de requêtes) sur {$hote}, tentative {$tentative}/" . self::TENTATIVES_PAR_SERVEUR . ", nouvel essai dans {$delai}s...");
                    sleep($delai);
                    continue;
                }

                $transitoire = in_array($statut, self::CODES_TRANSITOIRES, true);

                if (! $transitoire) {
                    $this->warn("  Echec Overpass sur {$hote} pour {$tag} (statut {$statut}, non transitoire), abandon de ce serveur.");
                    break;
                }

                if ($tentative < self::TENTATIVES_PAR_SERVEUR) {
                    $delai = 5 * $tentative;
                    $this->warn("  Statut {$statut} sur {$hote} (tentative {$tentative}/" . self::TENTATIVES_PAR_SERVEUR . "), nouvelle tentative dans {$delai}s...");
                    sleep($delai);
                } else {
                    $this->warn("  Statut {$statut} sur {$hote} apres " . self::TENTATIVES_PAR_SERVEUR . ' tentatives, passage au serveur suivant.');
                }
            }
        }

        return null;
    }

    private function donneesIncompletes(Company $company): bool
    {
        return empty($company->ville) || empty($company->code_postal) || empty($company->province);
    }

    // ============================================================
    // IMPORTER – VERSION COMPLÈTE (inchangée, elle récupère TOUS les tags)
    // ============================================================
    private function importer(array $elements, string $categorieLisible, bool $avecNominatim, bool $force): array
    {
        $nouveaux = 0;
        $misAJour = 0;
        $geocodes = 0;
        $echecsGeocodage = 0;

        foreach ($elements as $element) {
            try {
                $osmId = (string) $element['id'];
                $osmType = $element['type'];
                $tags = $element['tags'] ?? [];

                // ---------- CHAMPS DE BASE ----------
                $nom = $tags['name'] ?? $tags['official_name'] ?? $tags['alt_name'] ?? 'Sans nom';
                $adresse = $tags['addr:street'] ?? $tags['addr:place'] ?? null;
                $housenumber = $tags['addr:housenumber'] ?? null;
                if ($adresse && $housenumber) {
                    $adresse = $housenumber . ' ' . $adresse;
                }
                $ville = $tags['addr:city'] ?? $tags['addr:town'] ?? $tags['addr:village'] ?? null;
                $code_postal = $tags['addr:postcode'] ?? null;
                $latitude = $element['lat'] ?? null;
                $longitude = $element['lon'] ?? null;

                // ---------- CONTACTS ----------
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

                // ---------- ENRICHISSEMENT (réseaux sociaux + autres) ----------
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

                // Nettoyer les null / vides
                $social = array_filter($social, fn($v) => !is_null($v) && $v !== '');
                $enrichissement = !empty($social) ? json_encode($social, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;

                // ---------- TAILLE ENTREPRISE ----------
                $taille_entreprise = $tags['employees'] ?? $tags['capacity'] ?? $tags['max_employees'] ?? null;

                // ---------- STATUT ----------
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

                // ---------- NOTES ----------
                $note = isset($tags['rating']) ? (float) $tags['rating'] : null;
                $nb_avis = isset($tags['review_count']) ? (int) $tags['review_count'] : null;

                // ---------- RECHERCHE EXISTANT ----------
                $existant = Company::where('osm_id', $osmId)
                    ->where('osm_type', $osmType)
                    ->first();

                if ($existant) {
                    if ($force) {
                        // FORCE : écraser tout
                        $existant->nom = $nom;
                        $existant->categorie = $categorieLisible;
                        $existant->adresse = $adresse;
                        $existant->ville = $ville;
                        $existant->code_postal = $code_postal;
                        $existant->telephone = $telephone;
                        $existant->email = $email;
                        $existant->site_web = $site_web;
                        $existant->horaires = $horaires;
                        $existant->latitude = $latitude;
                        $existant->longitude = $longitude;
                        $existant->enrichissement = $enrichissement;
                        $existant->taille_entreprise = $taille_entreprise;
                        $existant->statut = $statut;
                        $existant->note = $note;
                        $existant->nb_avis = $nb_avis;
                    } else {
                        // NORMAL : ne remplacer que les vides
                        $existant->nom = empty($existant->nom) ? $nom : $existant->nom;
                        $existant->categorie = empty($existant->categorie) ? $categorieLisible : $existant->categorie;
                        $existant->adresse = empty($existant->adresse) ? $adresse : $existant->adresse;
                        $existant->code_postal = empty($existant->code_postal) ? $code_postal : $existant->code_postal;
                        $existant->telephone = empty($existant->telephone) ? $telephone : $existant->telephone;
                        $existant->email = empty($existant->email) ? $email : $existant->email;
                        $existant->site_web = empty($existant->site_web) ? $site_web : $existant->site_web;
                        $existant->horaires = empty($existant->horaires) ? $horaires : $existant->horaires;
                        $existant->enrichissement = empty($existant->enrichissement) ? $enrichissement : $existant->enrichissement;
                        $existant->taille_entreprise = empty($existant->taille_entreprise) ? $taille_entreprise : $existant->taille_entreprise;
                        $existant->statut = empty($existant->statut) ? $statut : $existant->statut;
                        $existant->note = empty($existant->note) ? $note : $existant->note;
                        $existant->nb_avis = empty($existant->nb_avis) ? $nb_avis : $existant->nb_avis;
                    }

                    $existant->latitude = $latitude ?? $existant->latitude;
                    $existant->longitude = $longitude ?? $existant->longitude;
                    $existant->derniere_verification = now();

                    if (empty($existant->ville)) {
                        $villeOverpass = $tags['addr:city'] ?? null;
                        if ($villeOverpass) {
                            $existant->ville = $villeOverpass;
                        }
                    }

                    if ($avecNominatim && $this->donneesIncompletes($existant) && $existant->latitude && $existant->longitude) {
                        $resultat = $this->geocoderAvecNominatim($existant);
                        $resultat === true ? $geocodes++ : ($resultat === false ? $echecsGeocodage++ : null);
                        sleep(1);
                    }

                    $existant->save();
                    $misAJour++;
                    continue;
                }

                // ---------- CRÉATION ----------
                $company = Company::create([
                    'nom' => $nom,
                    'categorie' => $categorieLisible,
                    'adresse' => $adresse,
                    'ville' => $ville,
                    'province' => null,
                    'region' => null,
                    'code_postal' => $code_postal,
                    'telephone' => $telephone,
                    'email' => $email,
                    'site_web' => $site_web,
                    'horaires' => $horaires,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'note' => $note,
                    'nb_avis' => $nb_avis,
                    'osm_id' => $osmId,
                    'osm_type' => $osmType,
                    'enrichissement' => $enrichissement,
                    'taille_entreprise' => $taille_entreprise,
                    'statut' => $statut,
                    'date_import' => now(),
                    'derniere_verification' => now(),
                ]);
                $nouveaux++;

                if ($avecNominatim && $this->donneesIncompletes($company) && $company->latitude && $company->longitude) {
                    $resultat = $this->geocoderAvecNominatim($company);
                    $resultat === true ? $geocodes++ : ($resultat === false ? $echecsGeocodage++ : null);
                    sleep(1);
                }

            } catch (\Exception $e) {
                $this->error("  ❌ Erreur sur OSM ID {$element['id']} : " . $e->getMessage());
                continue;
            }
        }

        return [$nouveaux, $misAJour, $geocodes, $echecsGeocodage];
    }

    private function geocoderAvecNominatim(Company $company): bool
    {
        try {
            $response = Http::withHeaders([
                    'User-Agent' => 'BelgiumBusinessExtractor/1.0 (contact: 3lmsolutions@gmail.com)',
                    'Accept' => 'application/json',
                ])
                ->timeout(30)
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $company->latitude,
                    'lon' => $company->longitude,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'accept-language' => 'fr',
                ]);
        } catch (ConnectionException $e) {
            $this->warn("  Nominatim injoignable pour {$company->nom} : " . $e->getMessage());
            return false;
        }

        if ($response->failed()) {
            $this->warn("  Nominatim: echec pour {$company->nom} (statut " . $response->status() . ')');
            return false;
        }

        $data = $response->json();
        $adresse = $data['address'] ?? [];

        if (empty($adresse)) {
            return false;
        }

        $ville = $adresse['city'] ?? $adresse['town'] ?? $adresse['village'] ?? $adresse['municipality'] ?? null;
        $provinceBrute = $adresse['county'] ?? $adresse['state'] ?? null;
        $province = $provinceBrute ? (self::NOMINATIM_PROVINCE_MAP[$provinceBrute] ?? null) : null;
        $codePostal = $adresse['postcode'] ?? null;

        $region = null;
        if ($province) {
            if (in_array($province, ['Brabant wallon', 'Hainaut', 'Liege', 'Luxembourg', 'Namur'])) {
                $region = 'Wallonie';
            } elseif (in_array($province, ['Anvers', 'Brabant flamand', 'Flandre-Occidentale', 'Flandre-Orientale', 'Limbourg'])) {
                $region = 'Flandre';
            } elseif ($province === 'Bruxelles-Capitale') {
                $region = 'Bruxelles-Capitale';
            }
        }

        $company->fill([
            'ville' => $company->ville ?: $ville,
            'province' => $company->province ?: $province,
            'region' => $company->region ?: $region,
            'code_postal' => $company->code_postal ?: $codePostal,
            'adresse_normalisee' => $data['display_name'] ?? null,
            'nominatim_place_id' => $data['place_id'] ?? null,
            'nominatim_classe' => $data['category'] ?? null,
            'nominatim_type' => $data['type'] ?? null,
            'geocode_source' => 'nominatim',
            'geocode_le' => now(),
        ]);

        try {
            $company->save();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->warn("  Erreur base de donnees pour {$company->nom} lors du geocodage : " . $e->getMessage());
            return false;
        }

        return true;
    }
}