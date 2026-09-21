<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDuplicateNotifications extends Command
{
    protected $signature = 'notifications:clean-duplicates
        {--dry-run : Affiche ce qui serait supprimé sans rien modifier}
        {--window=60 : Fenêtre en secondes pour considérer deux notifications comme doublons}';

    protected $description = 'Supprime les notifications dupliquées créées dans une courte fenêtre de temps';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $window = (int) $this->option('window');

        $this->info("🔍 Recherche des doublons (fenêtre : {$window}s)");
        if ($dryRun) {
            $this->warn("⚠️  Mode DRY-RUN : aucune suppression.");
        }

        $notifications = DB::table('notifications')
            ->orderBy('message')
            ->orderBy('created_at')
            ->get(['id', 'message', 'user_id', 'created_at']);

        $toDelete = [];
        $lastSeen = [];

        foreach ($notifications as $notif) {
            $key = $notif->user_id . '|' . md5($notif->message);

            if (isset($lastSeen[$key])) {
                $lastTime = strtotime($lastSeen[$key]);
                $currentTime = strtotime($notif->created_at);

                if (($currentTime - $lastTime) <= $window) {
                    $toDelete[] = $notif->id;
                    continue; // on garde le premier
                }
            }

            $lastSeen[$key] = $notif->created_at;
        }

        if (empty($toDelete)) {
            $this->info("✅ Aucun doublon trouvé.");
            return self::SUCCESS;
        }

        $this->info("📊 " . count($toDelete) . " doublon(s) détecté(s).");

        if ($dryRun) {
            $this->table(
                ['ID', 'Message', 'Créé le'],
                DB::table('notifications')
                    ->whereIn('id', array_slice($toDelete, 0, 15))
                    ->get(['id', 'message', 'created_at'])
                    ->map(fn($n) => [$n->id, substr($n->message, 0, 60) . '...', $n->created_at])
                    ->toArray()
            );
            $this->warn("🔎 DRY-RUN : " . count($toDelete) . " doublon(s) SERAIENT supprimés.");
            $this->info("Relance sans --dry-run pour appliquer.");
        } else {
            DB::table('notifications')->whereIn('id', $toDelete)->delete();
            $this->info("✅ " . count($toDelete) . " doublon(s) supprimé(s).");
        }

        return self::SUCCESS;
    }
}