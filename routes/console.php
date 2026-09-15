<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// ============================================================
// EXTRACTION HEBDOMADAIRE
// Tous les dimanches à 03:00
// ============================================================

Schedule::command('extraction:test --force')
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error("Échec de l'extraction automatique.");
    });

// ============================================================
// REMPLISSAGE DES CONTACTS MANQUANTS
// Tous les jours à 04:00
// ============================================================

Schedule::command('extraction:fill-contacts --force')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error("Échec du remplissage automatique des contacts.");
    });

// ============================================================
// REMPLISSAGE DES VILLES MANQUANTES
// Tous les jours à 05:00
// Maximum 100 entreprises par exécution
// ============================================================

Schedule::command('extraction:fill-cities --limit=100')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error("Échec du remplissage automatique des villes.");
    });

// ============================================================
// RÉINDEXATION ELASTICSEARCH
// Tous les jours à 06:00
// ============================================================

Schedule::command('elasticsearch:index-all')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error("Échec de la réindexation Elasticsearch.");
    });

// ============================================================
// COMMANDE INSPIRE DE LARAVEL
// ============================================================

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');