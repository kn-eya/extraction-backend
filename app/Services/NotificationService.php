<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    // Passe à true pour envoyer en direct (debug), false pour utiliser la queue
    protected bool $useQueue = false; // ← mets true pour tester sans worker

    public function importTermine(string $message): void
    {
        $this->notifierAdmins('import_termine', '✅ Import terminé', $message);
    }

    public function nouvelleRecherche(string $message): void
    {
        $this->notifierAdmins('nouvelle_recherche', '🔎 Nouvelle recherche', $message);
    }

    public function baseMiseAJour(string $message): void
    {
        $this->notifierAdmins('base_mise_a_jour', '🔄 Base mise à jour', $message);
    }

    public function nouveauxContacts(string $message): void
    {
        $this->notifierAdmins('nouveaux_contacts', '📇 Nouveaux contacts', $message);
    }

    private function notifierAdmins(string $type, string $titre, string $message): void
    {
        $admins = User::role('admin')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('Aucune notification envoyée : aucun admin trouvé.');
            return;
        }

        foreach ($admins as $admin) {
            try {
                $notification = AppNotification::creer($admin->id, $type, $titre, $message);
                if (!$notification) {
                    Log::error('Impossible de créer la notification en base.', [
                        'admin_id' => $admin->id,
                        'type' => $type,
                    ]);
                    continue;
                }

                if ($this->useQueue) {
                    Mail::to($admin->email)->queue(new NotificationMail($notification));
                } else {
                    Mail::to($admin->email)->send(new NotificationMail($notification));
                }

            } catch (\Throwable $e) {
                Log::error("Erreur lors de l'envoi d'une notification.", [
                    'admin_id'  => $admin->id,
                    'email'     => $admin->email,
                    'type'      => $type,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}