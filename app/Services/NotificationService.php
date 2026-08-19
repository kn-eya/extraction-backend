<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Notification : Import terminé.
     */
    public function importTermine(string $message): void
    {
        $this->notifierAdmins(
            type: 'import_termine',
            titre: 'Import terminé',
            message: $message
        );
    }

    /**
     * Notification : Nouvelle recherche.
     */
    public function nouvelleRecherche(string $message): void
    {
        $this->notifierAdmins(
            type: 'nouvelle_recherche',
            titre: 'Nouvelle recherche',
            message: $message
        );
    }

    /**
     * Notification : Base mise à jour.
     */
    public function baseMiseAJour(string $message): void
    {
        $this->notifierAdmins(
            type: 'base_mise_a_jour',
            titre: 'Base mise à jour',
            message: $message
        );
    }

    /**
     * Crée la notification dans l'interface
     * et programme l'envoi de l'email à tous les administrateurs.
     */
    private function notifierAdmins(
        string $type,
        string $titre,
        string $message
    ): void {
        $admins = User::role('admin')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('Aucune notification envoyée : aucun utilisateur avec le rôle admin.');

            return;
        }

        foreach ($admins as $admin) {
            try {
                /*
                 * 1. Notification dans l'interface
                 */
                $notification = AppNotification::creer(
                    userId: $admin->id,
                    type: $type,
                    titre: $titre,
                    message: $message
                );

                /*
                 * Si la notification DB n'a pas pu être créée,
                 * on ne programme pas l'email.
                 */
                if (!$notification) {
                    Log::error(
                        'Impossible de créer une notification.',
                        [
                            'admin_id' => $admin->id,
                            'type' => $type,
                        ]
                    );

                    continue;
                }

                /*
                 * 2. Email
                 *
                 * L'envoi est placé dans la queue Laravel.
                 */
                Mail::to($admin->email)
                    ->queue(new NotificationMail($notification));

            } catch (\Throwable $e) {
                /*
                 * Une erreur de notification ne doit pas
                 * faire échouer la recherche ou l'import.
                 */
                Log::error(
                    "Erreur lors de l'envoi d'une notification.",
                    [
                        'admin_id' => $admin->id,
                        'email' => $admin->email,
                        'type' => $type,
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }
    }
}