<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class RefreshOpenStatus extends Command
{
    protected $signature = 'companies:refresh-open-status';
    protected $description = 'Met à jour le statut "ouvert" pour toutes les entreprises';

    public function handle()
    {
        $count = Company::count();
        if ($count === 0) {
            $this->info('Aucune entreprise.');
            return 0;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        Company::chunk(100, function ($companies) use ($bar) {
            foreach ($companies as $company) {
                $company->est_ouvert = $company->isOpenNow();
                $company->saveQuietly();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Statut "ouvert" mis à jour.');
        return 0;
    }
}