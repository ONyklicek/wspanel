<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_in_returns_to_the_panels_address_not_the_internal_one(): void
    {
        config(['app.url' => 'https://panel.example.com:8444']);
        $user = User::factory()->create(['password' => bcrypt('Heslo-123456')]);

        // As the web tier forwards it: the internal host, plain HTTP.
        $this->get('http://wspanel-panel.internal/sites')->assertRedirect('https://panel.example.com:8444/login');
        $this->post('http://wspanel-panel.internal/login', ['email' => $user->email, 'password' => 'Heslo-123456'])
            ->assertRedirect('https://panel.example.com:8444/sites');
    }
}
