<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CompanySearchController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ElasticsearchSearchController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TwoFactorController;   // ⬅️ AJOUT

// Routes publiques
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// ⬇️ 2e étape du login — PUBLIQUE (l'utilisateur n'a pas encore de token Sanctum)
Route::post('/login/2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:10,1');

// Routes protégées par authentification
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ========== DOUBLE AUTHENTIFICATION (2FA) ==========
    Route::prefix('2fa')->group(function () {
        Route::get('/status',   [TwoFactorController::class, 'status']);
        Route::post('/enable',  [TwoFactorController::class, 'enable']);
        Route::post('/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/disable', [TwoFactorController::class, 'disable']);
    });

    // ========== NOTIFICATIONS ==========
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('can:manage-notifications');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('can:manage-notifications');
    Route::patch('/notifications/{id}/lue', [NotificationController::class, 'marquerLue'])->middleware('can:manage-notifications');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'marquerLue'])->middleware('can:manage-notifications');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->middleware('can:manage-notifications');

    // ========== RECHERCHE AVANCÉE (Elasticsearch) ==========
    Route::post('/search/elasticsearch', [ElasticsearchSearchController::class, 'search'])->middleware('can:search-companies');
    Route::get('/companies/search', [CompanySearchController::class, 'search'])->middleware('can:search-companies');

    // ========== HISTORIQUE DES RECHERCHES ==========
    Route::get('/search-history', function (Request $request) {
        return $request->user()->searchHistory()->latest()->paginate(20);
    });

    // ========== EXPORT ==========
    Route::get('/export', [ExportController::class, 'export'])->name('api.export');

    // ========== EXPORT SINGLE ==========
    Route::get('/company/{id}/export', [ExportController::class, 'exportSingle'])
        ->middleware(['auth:sanctum', 'can:export-companies']);

    // ========== ACTIVITIES ==========
    Route::get('/activities', function (Request $request) {
        return \App\Models\Activity::orderByDesc('created_at')->paginate(20);
    });
});

// Routes réservées aux administrateurs
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('can:view-dashboard');
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('can:view-dashboard');
    Route::get('/companies/export', [ExportController::class, 'export'])->middleware('can:export-companies');
    Route::get('/companies/export/preview', [ExportController::class, 'preview'])->middleware('can:export-companies');

    Route::get('/stats', [DashboardController::class, 'stats'])->middleware('can:view-dashboard');
});

// ========== SUGGESTIONS (autocomplétion) ==========
Route::get('/search/suggestions', [ElasticsearchSearchController::class, 'suggestions'])->middleware('auth:sanctum');
Route::get('/suggestions/secteurs', [ElasticsearchSearchController::class, 'suggestSecteurs'])->middleware('auth:sanctum');
Route::get('/suggestions/villes', [ElasticsearchSearchController::class, 'suggestVilles'])->middleware('auth:sanctum');
Route::get('/suggestions/provinces', [ElasticsearchSearchController::class, 'suggestProvinces'])->middleware('auth:sanctum');
Route::get('/suggestions/codes_postaux', [ElasticsearchSearchController::class, 'suggestCodesPostaux'])->middleware('auth:sanctum');
Route::get('/suggestions/regions', [ElasticsearchSearchController::class, 'suggestRegions'])->middleware('auth:sanctum');