<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/dashboard');
        $response->assertStatus(401);
    }

    public function test_non_admin_user_cannot_access_dashboard(): void
    {
        // Créer un user sans rôle admin
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard');

        $response->assertStatus(403);
    }

    public function test_admin_user_can_access_dashboard(): void
    {
        // Créer un user avec rôle admin
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard');

        $response->assertStatus(200);
    }
}