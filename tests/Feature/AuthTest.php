<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/orders');

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'شما اجازه دسترسی به این بخش را ندارید.',
            ]);
    }

    public function test_guest_cannot_access_admin_routes(): void
    {
        $response = $this->getJson('/api/admin/orders');

        $response->assertUnauthorized();
    }
}
