<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class CleanForeignCompanies extends Command
{
    protected $signature = 'extraction:clean-foreign
        {--dry-run : liste ce qui serait supprime sans rien supprimer}';

    protected $description = 'Supprime les entreprises importees par erreur hors de Belgique (pollution due a la bbox rectangulaire).';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $horsBelgiqueGeocodees = Company::whereNotNull('adresse_normalisee')
            ->where('adresse_normalisee', 'not like', '%Belgique')
            ->get();

        $horsBelgiqueParCodePostal = Company::whereNull('adresse_normalisee')
            ->whereNotNull('code_postal')
            ->where(function ($q) {
                $q->whereRaw("LENGTH(code_postal) != 4")
                  ->orWhereRaw("code_postal !~ '^[0-9]{4}$'");
            })
            ->get();

        $total = $horsBelgiqueGeocodees->count() + $horsBelgiqueParCodePostal->count();

        $this->info("Detectees via adresse Nominatim (hors Belgique confirme) : {$horsBelgiqueGeocodees->count()}");
        $this->info("Detectees via format de code postal suspect : {$horsBelgiqueParCodePostal->count()}");
        $this->info("TOTAL a supprimer : {$total}");

        if ($total === 0) {
            $this->info('Rien a nettoyer.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Mode --dry-run : rien ne sera supprime. Exemples :');
            foreach ($horsBelgiqueGeocodees->take(10) as $c) {
                $this->line("  [Nominatim] {$c->nom} -> {$c->adresse_normalisee}");
            }
            foreach ($horsBelgiqueParCodePostal->take(10) as $c) {
                $this->line("  [CP suspect] {$c->nom} -> CP: {$c->code_postal}");
            }
            return self::SUCCESS;
        }

        if (! $this->confirm("Confirmer la suppression de {$total} entreprise(s) ?")) {
            $this->info('Annule.');
            return self::SUCCESS;
        }

        $supprimees = 0;
        foreach ($horsBelgiqueGeocodees as $c) {
            $c->delete();
            $supprimees++;
        }
        foreach ($horsBelgiqueParCodePostal as $c) {
            $c->delete();
            $supprimees++;
        }

        $this->info("{$supprimees} entreprise(s) supprimee(s).");

        return self::SUCCESS;
    }
}