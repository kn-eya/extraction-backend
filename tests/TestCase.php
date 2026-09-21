<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Réinitialiser le cache des permissions (avant de créer les rôles)
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Créer les rôles nécessaires pour les tests
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
       
        // Réinitialiser le cache après création
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}