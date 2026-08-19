<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportController extends Controller
{
    /**
     * GET /api/companies/export
     *
     * Memes filtres que la recherche (secteur, ville, region, province, code_postal, rayon)
     * + format=csv|json|xml|sql|excel (defaut: csv)
     * Pas de pagination : exporte tous les resultats correspondant aux filtres.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'secteur' => 'nullable|string|max:255',
            'mot_cle' => 'nullable|string|max:255',
            'ville' => 'nullable|string|max:255',
            'region' => 'nullable|string|in:Wallonie,Flandre,Bruxelles-Capitale',
            'province' => 'nullable|string|max:255',
            'code_postal' => 'nullable|string|max:10',
            'lat' => 'nullable|numeric|between:-90,90',
            'lon' => 'nullable|numeric|between:-180,180',
            'rayon' => 'nullable|numeric|min:5|max:100',
            'format' => 'nullable|string|in:csv,json,xml,sql,excel',
            'limit' => 'nullable|integer|min:1|max:50000',
        ]);

        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(['admin', 'professionnel'])) {
            abort(403, 'Vous n\'avez pas les droits pour exporter des données.');
        }

        $format = $validated['format'] ?? 'csv';
        $limit = $validated['limit'] ?? 5000;

        $query = Company::query()->where('statut', 'actif');

        if (! empty($validated['secteur'])) {
            $query->where('categorie', 'ilike', '%' . $validated['secteur'] . '%');
        }
        if (! empty($validated['mot_cle'])) {
            $query->where('nom', 'ilike', '%' . $validated['mot_cle'] . '%');
        }
        if (! empty($validated['ville'])) {
            $query->where('ville', 'ilike', '%' . $validated['ville'] . '%');
        }
        if (! empty($validated['region'])) {
            $query->where('region', $validated['region']);
        }
        if (! empty($validated['province'])) {
            $query->whereRaw('unaccent(province) ILIKE unaccent(?)', ['%' . $validated['province'] . '%']);
        }
        if (! empty($validated['code_postal'])) {
            $query->where('code_postal', $validated['code_postal']);
        }

        $rayonActif = ! empty($validated['lat']) && ! empty($validated['lon']) && ! empty($validated['rayon']);
        if ($rayonActif) {
            $lat = $validated['lat'];
            $lon = $validated['lon'];
            $rayonKm = $validated['rayon'];
            $distanceExpr = "
                6371 * acos(
                    LEAST(1, GREATEST(-1,
                        cos(radians(?)) * cos(radians(latitude)) *
                        cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    ))
                )
            ";
            $query->selectRaw("companies.*, ({$distanceExpr}) as distance_km", [$lat, $lon, $lat])
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereRaw("({$distanceExpr}) <= ?", [$lat, $lon, $lat, $rayonKm])
                ->orderBy('distance_km');
        }

        // Colonnes exportees : les champs metier utiles, pas les colonnes techniques internes
        $colonnes = ['nom', 'categorie', 'adresse', 'ville', 'province', 'region', 'code_postal',
            'telephone', 'email', 'site_web', 'note', 'nb_avis', 'latitude', 'longitude', 'statut'];

$companies = $query->get($colonnes);

        $formatAffiche = match ($format) {
            'csv' => 'CSV',
            'json' => 'JSON',
            'xml' => 'XML',
            'sql' => 'SQL',
            'excel' => 'Excel',
            default => 'CSV',
        };
$export = Export::create([
            'user_id' => $request->user()?->id,
            'type_export' => 'Entreprise',
            'format' => $formatAffiche,
            'nombre_lignes' => $companies->count(),
            'date_export' => now(),
        ]);

        \App\Models\Activity::log('export', "Export {$formatAffiche} ({$companies->count()} lignes)", [
            'user_id' => $request->user()?->id,
            'export_id' => $export->id,
        ]);

        $nomFichier = 'export_entreprises_' . now()->format('Y-m-d_His');

        return match ($format) {
            'json' => $this->exportJson($companies, $nomFichier),
            'xml' => $this->exportXml($companies, $nomFichier),
            'sql' => $this->exportSql($companies, $nomFichier),
            'excel' => $this->exportExcel($companies, $colonnes, $nomFichier),
            default => $this->exportCsv($companies, $colonnes, $nomFichier),
        };
    }

    private function exportCsv($companies, array $colonnes, string $nomFichier)
    {
        $callback = function () use ($companies, $colonnes) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $colonnes);
            foreach ($companies as $c) {
                fputcsv($out, $c->only($colonnes));
            }
            fclose($out);
        };

        return Response::stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}.csv\"",
        ]);
    }

    private function exportJson($companies, string $nomFichier)
    {
        return Response::make($companies->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}.json\"",
        ]);
    }

    private function exportXml($companies, string $nomFichier)
    {
        $xml = new \SimpleXMLElement('<entreprises/>');
        foreach ($companies as $c) {
            $item = $xml->addChild('entreprise');
            foreach ($c->getAttributes() as $cle => $valeur) {
                if (is_array($valeur) || is_object($valeur)) {
                    continue;
                }
                $item->addChild($cle, htmlspecialchars((string) $valeur));
            }
        }

        return Response::make($xml->asXML(), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}.xml\"",
        ]);
    }

    private function exportSql($companies, string $nomFichier)
    {
        $lignes = ["-- Export entreprises genere le " . now()->toDateTimeString()];
        foreach ($companies as $c) {
            $valeurs = $c->getAttributes();
            $colonnesSql = array_keys($valeurs);
            $valeursEchappees = array_map(function ($v) {
                if (is_null($v)) return 'NULL';
                return "'" . str_replace("'", "''", (string) $v) . "'";
            }, $valeurs);

            $lignes[] = sprintf(
                "INSERT INTO companies (%s) VALUES (%s);",
                implode(', ', $colonnesSql),
                implode(', ', $valeursEchappees)
            );
        }

        return Response::make(implode("\n", $lignes), 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}.sql\"",
        ]);
    }

    private function exportExcel($companies, array $colonnes, string $nomFichier)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($colonnes as $i => $col) {
            $sheet->setCellValue([$i + 1, 1], $col);
        }

        $ligne = 2;
        foreach ($companies as $c) {
            $donnees = $c->only($colonnes);
            foreach (array_values($donnees) as $i => $valeur) {
                $sheet->setCellValue([$i + 1, $ligne], $valeur);
            }
            $ligne++;
        }

        $writer = new Xlsx($spreadsheet);
        $cheminTemp = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
        $writer->save($cheminTemp);

        return response()->download($cheminTemp, "{$nomFichier}.xlsx")->deleteFileAfterSend(true);
    }
}