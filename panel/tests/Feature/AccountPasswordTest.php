<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Nobody sets another person's password, not even an administrator. */
class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_users_password_cannot_be_changed(): void
    {
        $other = User::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->expectException(AuthorizationException::class);
        $other->update(['password' => 'Nove-heslo-12345']);
    }

    public function test_the_edit_screen_of_another_user_offers_no_password(): void
    {
        $other = User::factory()->create(['name' => 'Bob']);
        $this->actingAs(User::factory()->create());

        $this->get("/users/{$other->id}/edit")->assertOk()->assertSee('Bob')->assertDontSee('new-password');
    }

    public function test_own_password_can_be_changed(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        $me->update(['password' => 'Nove-heslo-12345']);

        $this->assertTrue(Hash::check('Nove-heslo-12345', $me->fresh()->password));
    }

    public function test_a_password_reset_without_a_session_still_works(): void
    {
        $user = User::factory()->create();

        $user->forceFill(['password' => 'Reset-heslo-12345'])->save();

        $this->assertTrue(Hash::check('Reset-heslo-12345', $user->fresh()->password));
    }
}
