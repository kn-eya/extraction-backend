<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_requires_auth(): void
    {
        $response = $this->postJson('/api/search/elasticsearch', []);
        $response->assertStatus(401);
    }

    public function test_search_with_auth(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/search/elasticsearch', [
                'query' => ['match_all' => (object) []],
                'size' => 5,
            ]);

        $response->assertStatus(200);
    }
}