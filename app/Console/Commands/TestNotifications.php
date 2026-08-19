<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class TestNotifications extends Command
{
    protected $signature = 'notifications:test
        {--type=import : type de notification a tester (import, recherche, maj, contacts)}';

    protected $description = 'Teste l\'envoi d\'une notification (interface + email) a tous les admins existants en base.';

    public function handle(NotificationService $notifications): int
    {
        $admins = User::role('admin')->get(['id', 'name', 'email']);

        if ($admins->isEmpty()) {
            $this->error('Aucun utilisateur avec le role "admin" trouve en base. Rien a tester.');
            return self::FAILURE;
        }

        $this->info('Admins trouves en base :');
        foreach ($admins as $admin) {
            $this->line("  - {$admin->name} ({$admin->email})");
        }

        $message = 'Ceci est un test de notification, envoye le ' . now()->format('d/m/Y H:i');

        match ($this->option('type')) {
            'recherche' => $notifications->nouvelleRecherche($message),
            'maj' => $notifications->baseMiseAJour($message),
            'contacts' => $notifications->nouveauxContacts($message),
            default => $notifications->importTermine($message),
        };

        $this->info('Notification envoyee (interface + email en queue) a ' . $admins->count() . ' admin(s).');

        return self::SUCCESS;
    }
}