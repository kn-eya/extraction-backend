<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CompanySearchController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ElasticsearchSearchController;
use App\Http\Controllers\Api\NotificationController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/lue', [NotificationController::class, 'marquerLue']);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/search/elasticsearch', [ElasticsearchSearchController::class, 'search']);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
});
Route::middleware('auth:sanctum')->get('/companies/export', [ExportController::class, 'export']);
Route::post('/debug-echo', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'all' => $request->all(),
        'raw_content' => $request->getContent(),
        'content_length_header' => $request->header('Content-Length'),
    ]);
});
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->get('/companies/search', [CompanySearchController::class, 'search']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});