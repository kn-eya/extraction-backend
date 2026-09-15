<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(401);
    }

    public function test_non_admin_user_cannot_access_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->getJson('/api/dashboard');

        $response->assertStatus(403);
    }

    public function test_admin_user_can_access_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'api')->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'total_entreprises',
                'par_region',
                'par_province',
                'par_ville',
                'par_categorie',
                'evolution_imports',
                'nouveaux_contacts_7j',
                'cartes',
                'stats_complementaires',
            ],
        ]);
    }
}
