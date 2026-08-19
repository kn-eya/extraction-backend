protected $middlewareGroups = [
    'api' => [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        'throttle:api',  // ← C’est ici
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ],
];