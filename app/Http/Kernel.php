use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule;

// Extraction hebdomadaire complète (tous les dimanches à 3h du matin)
Schedule::command('extraction:test --force --sans-nominatim')
    ->weeklyOn(7, '3:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Log::error('Échec de l\'extraction automatique');
    });

// Remplissage des champs manquants (contacts, réseaux sociaux, etc.) – une fois par jour
Schedule::command('extraction:fill-contacts --force')
    ->dailyAt('4:00')
    ->withoutOverlapping();

// Remplissage des villes / provinces / codes postaux (via Photon) – toutes les 6 heures
Schedule::command('extraction:fill-cities --limit=200')
    ->everySixHours()
    ->withoutOverlapping();

// Mise à jour des téléphones (si besoin) – une fois par semaine
Schedule::command('extraction:fill-phone')
    ->weeklyOn(1, '5:00') // lundi à 5h
    ->withoutOverlapping();

// Correction des régions manquantes (rapide, peut être quotidien)
Schedule::command('extraction:fix-regions')
    ->dailyAt('6:00')
    ->withoutOverlapping();
protected function schedule(Schedule $schedule)
{
    $schedule->command('companies:refresh-open-status')->everyThirtyMinutes();
}


protected $middlewareGroups = [
    'api' => [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        'throttle:api',  // ← C’est ici
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ],
];
protected $routeMiddleware = [
    // ... autres middlewares
    'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
    'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
];
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    ]);
})