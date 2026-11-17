<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // بررسی دسترسی کاربر عادی به API مدیریت
    public function test_normal_user_can_access_admin_routes(): void
    {
        User::factory()->create([
            'is_admin' => false,
        ]);

        $this->getJson('/api/admin/orders')
            ->assertOk();
    }

    // بررسی دسترسی کاربر مهمان به API مدیریت
    public function test_guest_can_access_admin_routes(): void
    {
        $this->getJson('/api/admin/orders')
            ->assertOk();
    }
}
