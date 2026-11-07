<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_nobody_can_register_an_account(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'secret-secret', 'password_confirmation' => 'secret-secret']);
        $this->assertSame(0, User::count());
    }

    public function test_the_panel_needs_a_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/db-open/1/adminer')->assertRedirect('/login');
        $this->get('/ssl/root-ca.crt')->assertRedirect('/login');
    }
}
