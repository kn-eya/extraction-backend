<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_requires_auth(): void
    {
        $response = $this->getJson('/api/notifications');
        $response->assertStatus(401);
    }

    public function test_notifications_list_with_auth(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications');

        $response->assertStatus(200);
    }

    public function test_anti_duplicate_notification(): void
    {
        $user = User::factory()->create();

        $notif1 = AppNotification::creer($user->id, 'test', 'Titre', 'Message');
        $notif2 = AppNotification::creer($user->id, 'test', 'Titre', 'Message');

        $this->assertNotNull($notif1);
        $this->assertNull($notif2); // Doublon bloqué

        $this->assertEquals(1, AppNotification::count());
    }
}