<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_reset_password_with_full_name(): void
    {
        $user = User::create([
            'name' => 'Ms. Reyes',
            'staff_id' => 'T-10001',
            'password' => 'password',
            'role' => 'teacher',
        ]);
        $user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/forgot-password', [
            'name' => '  ms.   reyes ',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_full_name_is_rejected(): void
    {
        $response = $this->postJson('/api/forgot-password', [
            'name' => 'Unknown Teacher',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_duplicate_teacher_names_are_rejected(): void
    {
        foreach ([['T-10001', 'Padma Reyes'], ['T-10002', 'Padma Reyes']] as [$staffId, $name]) {
            User::create([
                'name' => $name,
                'staff_id' => $staffId,
                'password' => 'password',
                'role' => 'teacher',
            ]);
        }

        $response = $this->postJson('/api/forgot-password', [
            'name' => 'Padma Reyes',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
