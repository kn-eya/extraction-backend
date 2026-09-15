<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Company;
use App\Services\ElasticsearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportController extends Controller
{
    protected ElasticsearchService $es;

    public function __construct(ElasticsearchService $es)
    {
        $this->es = $es;
    }

    // =========================================================
    // EXPORT MULTIPLE (résultats de recherche filtrés)
    // =========================================================

    public function export(Request $request)
    {
        $validated = $request->validate([
            'secteur'      => 'nullable|string|max:255',
            'mot_cle'      => 'nullable|string|max:255',
            'ville'        => 'nullable|string|max:255',
            'region'       => 'nullable|string|max:255',
            'province'     => 'nullable|string|max:255',
            'code_postal'  => 'nullable|string|max:10',
            'lat'          => 'nullable|numeric|between:-90,90',
            'lon'          => 'nullable|numeric|between:-180,180',
            'rayon'        => 'nullable|numeric|min:1|max:500',
            'has_website'  => 'nullable|boolean',
            'has_phone'    => 'nullable|boolean',
            'has_email'    => 'nullable|boolean',
            'has_social'   => 'nullable|boolean',
            'format'       => 'nullable|string|in:csv,xlsx,json,xml,sql',
        ]);

        $format = $validated['format'] ?? 'csv';
        $perPage = 1000;
        $from = 0;
        $allData = [];

        // Construction de la requête Elasticsearch
        $must = [];
        $filter = [];

        if (!empty($validated['mot_cle'])) {
            $must[] = [
                'multi_match' => [
                    'query'     => $validated['mot_cle'],
                    'fields'    => ['categorie^10', 'nom^3', 'ville^2', 'adresse', 'province'],
                    'fuzziness' => 'AUTO',
                ],
            ];
        }

        foreach (['ville', 'province', 'region', 'code_postal', 'statut'] as $field) {
            if (empty($validated[$field])) continue;
            if (in_array($field, ['region', 'code_postal', 'statut'])) {
                $filter[] = ['term' => [$field => $validated[$field]]];
            } else {
                $filter[] = ['match_phrase' => [$field => $validated[$field]]];
            }
        }

        if (!empty($validated['secteur'])) {
            $filter[] = [
                'match' => [
                    'categorie' => [
                        'query'    => $validated['secteur'],
                        'operator' => 'and',
                    ],
                ],
            ];
        }

        if (!empty($validated['lat']) && !empty($validated['lon']) && !empty($validated['rayon'])) {
            $filter[] = [
                'geo_distance' => [
                    'distance' => $validated['rayon'] . 'km',
                    'location' => [
                        'lat' => (float) $validated['lat'],
                        'lon' => (float) $validated['lon'],
                    ],
                ],
            ];
        }

        if (!empty($validated['has_website'])) {
            $filter[] = ['exists' => ['field' => 'site_web']];
        }
        if (!empty($validated['has_phone'])) {
            $filter[] = ['exists' => ['field' => 'telephone']];
        }
        if (!empty($validated['has_email'])) {
            $filter[] = ['exists' => ['field' => 'email']];
        }
        if (!empty($validated['has_social'])) {
            $filter[] = ['exists' => ['field' => 'reseaux_sociaux']];
        }

        $query = [
            'bool' => [
                'must'   => $must,
                'filter' => $filter,
            ],
        ];
        if (empty($must)) {
            $query['bool']['must'] = [['match_all' => new \stdClass()]];
        }

        $sourceFields = ['nom', 'categorie', 'ville', 'region', 'province', 'code_postal', 'adresse', 'telephone', 'site_web', 'email', 'reseaux_sociaux', 'latitude', 'longitude'];

        while (true) {
            $params = [
                'size' => $perPage,
                'from' => $from,
                'query' => $query,
                'sort' => ['_doc' => ['order' => 'asc']],
                '_source' => $sourceFields,
            ];

            $response = $this->es->search($params);
            $hits = $response['hits']['hits'] ?? [];

            if (empty($hits)) {
                break;
            }

            foreach ($hits as $hit) {
                $allData[] = $hit['_source'];
            }

            if (count($hits) < $perPage) {
                break;
            }

            $from += $perPage;
        }

        // Journalisation
        Activity::log(
            'export',
            "Export {$format} - " . count($allData) . " lignes",
            [
                'user_id' => auth()->id(),
                'format' => $format,
                'filters' => $validated,
                'count' => count($allData),
            ]
        );

        return $this->generateExportFile($allData, $format);
    }

    // =========================================================
    // EXPORT D'UNE SEULE ENTREPRISE
    // =========================================================

    public function exportSingle(Request $request, int $id)
    {
        $format = $request->input('format', 'csv');

        if (!in_array($format, ['csv', 'xlsx', 'json', 'xml', 'sql'])) {
            return response()->json(['error' => 'Format non supporté'], 400);
        }

        try {
            $company = Company::findOrFail($id);
            $data = [$company->toArray()];

            Activity::log(
                'export_single',
                "Export d'une entreprise (ID: $id) en $format",
                [
                    'user_id' => auth()->id(),
                    'company_id' => $id,
                    'format' => $format,
                    'company_name' => $company->nom,
                ]
            );

            return $this->generateExportFile($data, $format);
        } catch (\Exception $e) {
            Log::error('Export single error', [
                'message' => $e->getMessage(),
                'id' => $id,
            ]);
            return response()->json(['error' => 'Entreprise non trouvée ou erreur'], 404);
        }
    }

    // =========================================================
    // MÉTHODES D'EXPORT (GÉNÉRATION DES FICHIERS)
    // =========================================================

    protected function generateExportFile(array $data, string $format)
    {
        $cleanData = array_map(function ($item) {
            foreach ($item as $key => $value) {
                if ($value === null) $item[$key] = '';
                if (is_array($value)) {
                    $item[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
            return $item;
        }, $data);

        $headers = [
            'Nom', 'Catégorie', 'Ville', 'Région', 'Province', 'Code postal',
            'Adresse', 'Téléphone', 'Email', 'Site web', 'Réseaux sociaux',
            'Latitude', 'Longitude'
        ];

        $rows = [];
        foreach ($cleanData as $item) {
            $rows[] = [
                $item['nom'] ?? '',
                $item['categorie'] ?? '',
                $item['ville'] ?? '',
                $item['region'] ?? '',
                $item['province'] ?? '',
                $item['code_postal'] ?? '',
                $item['adresse'] ?? '',
                $item['telephone'] ?? '',
                $item['email'] ?? '',
                $item['site_web'] ?? '',
                $item['reseaux_sociaux'] ?? '',
                $item['latitude'] ?? '',
                $item['longitude'] ?? '',
            ];
        }

        switch ($format) {
            case 'csv': return $this->exportCsv($headers, $rows);
            case 'xlsx': return $this->exportExcel($headers, $rows);
            case 'json': return $this->exportJson($cleanData);
            case 'xml': return $this->exportXml($cleanData);
            case 'sql': return $this->exportSql($cleanData);
            default: return response()->json(['error' => 'Format non supporté'], 400);
        }
    }

    protected function exportCsv(array $headers, array $rows)
    {
        $callback = function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($handle, $row, ';');
            }
            fclose($handle);
        };
        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="export_' . date('Y-m-d_H-i') . '.csv"',
        ]);
    }

    protected function exportExcel(array $headers, array $rows)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }
        $rowIndex = 2;
        foreach ($rows as $row) {
            $col = 'A';
            foreach ($row as $value) {
                $sheet->setCellValue($col . $rowIndex, $value);
                $col++;
            }
            $rowIndex++;
        }
        $writer = new Xlsx($spreadsheet);
        $filename = 'export_' . date('Y-m-d_H-i') . '.xlsx';
        return response()->stream(
            function () use ($writer) {
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    protected function exportJson(array $data)
    {
        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="export_' . date('Y-m-d_H-i') . '.json"',
        ]);
    }

    protected function exportXml(array $data)
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><entreprises></entreprises>');
        foreach ($data as $item) {
            $entreprise = $xml->addChild('entreprise');
            foreach ($item as $key => $value) {
                $entreprise->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
            }
        }
        return response($xml->asXML(), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="export_' . date('Y-m-d_H-i') . '.xml"',
        ]);
    }

    protected function exportSql(array $data)
    {
        $tableName = 'companies';
        $columns = ['nom', 'categorie', 'ville', 'region', 'province', 'code_postal', 'adresse', 'telephone', 'email', 'site_web', 'reseaux_sociaux', 'latitude', 'longitude'];
        $sql = "-- Export SQL (INSERT)\n-- Table: $tableName\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($data as $item) {
            $values = [];
            foreach ($columns as $col) {
                $val = $item[$col] ?? '';
                $val = str_replace("'", "''", $val);
                $values[] = "'" . $val . "'";
            }
            $sql .= "INSERT INTO $tableName (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
        }
        return response($sql, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="export_' . date('Y-m-d_H-i') . '.sql"',
        ]);
    }
}