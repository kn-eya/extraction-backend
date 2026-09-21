<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_auth(): void
    {
        $response = $this->getJson('/api/companies/export');
        $response->assertStatus(401);
    }

    public function test_export_with_admin_auth(): void
    {
        // Créer seulement 3 entreprises (au lieu de 54 000)
        Company::factory()->count(3)->create([
            'categorie' => 'Restaurant',
        ]);

        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/companies/export?categorie=Restaurant');

        $response->assertStatus(200);
    }
}