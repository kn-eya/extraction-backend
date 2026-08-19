<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class FixMissingRegions extends Command
{
    protected $signature = 'extraction:fix-regions';
    protected $description = 'Recalcule la region de toutes les entreprises ayant une province mais pas de region (aucun appel reseau, tout est local).';

    public function handle(): int
    {
        $companies = Company::whereNotNull('province')
            ->where('province', '!=', '')
            ->where(function ($q) {
                $q->whereNull('region')->orWhere('region', '');
            })
            ->get();

        $this->info("{$companies->count()} entreprise(s) avec province mais sans region.");

        if ($companies->isEmpty()) {
            $this->info('Rien a corriger.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($companies->count());
        $bar->start();

        $corrigees = 0;

        foreach ($companies as $company) {
            $company->save();
            if ($company->region) {
                $corrigees++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("{$corrigees} entreprise(s) ont maintenant une region calculee.");

        $sansCorrespondance = $companies->count() - $corrigees;
        if ($sansCorrespondance > 0) {
            $this->warn("{$sansCorrespondance} entreprise(s) ont une province sans correspondance dans BelgiumGeography.");
        }

        return self::SUCCESS;
    }
}