<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_uniqueness_is_checked_after_normalizing_case(): void
    {
        User::factory()->create(['email' => 'member@example.test']);
        $this->post(route('register'), [
            'name' => 'Duplicate Member', 'email' => 'MEMBER@EXAMPLE.TEST',
            'password' => 'A-long-test-password', 'password_confirmation' => 'A-long-test-password', 'role' => 'student', 'terms' => '1',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_member_can_sign_in_using_uppercase_email(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'A-long-test-password']);
        $this->post(route('login'), ['email' => 'MEMBER@EXAMPLE.TEST', 'password' => 'A-long-test-password'])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
