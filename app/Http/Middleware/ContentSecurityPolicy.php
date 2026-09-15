<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // 🚀 Ne pas modifier les en-têtes des réponses de téléchargement (StreamedResponse)
        if ($response instanceof StreamedResponse) {
            return $response;
        }

        // Politique stricte pour les API et le frontend
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
               "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
               "font-src 'self' https://fonts.gstatic.com; " .
               "img-src 'self' data:; " .
               "connect-src 'self' http://localhost:9200;";

        $response->header('Content-Security-Policy', $csp);
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}