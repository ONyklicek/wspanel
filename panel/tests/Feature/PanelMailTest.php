<?php

namespace Tests\Feature;

use App\Livewire\Pages\NotificationsPage;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Support\PanelMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class PanelMailTest extends TestCase
{
    use RefreshDatabase, RunsWireActions;

    public function test_an_external_smtp_server_takes_the_panels_mail(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create(['email' => 'admin@example.com']));

        $this->pageAction(Livewire::test(NotificationsPage::class), 'smtp', ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 'x', 'encryption' => 'tls'])->assertHasErrors();
        $this->pageAction(Livewire::test(NotificationsPage::class), 'smtp', [
            'enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'panel@example.com', 'password' => 's3cret', 'from_address' => 'panel@example.com', 'from_name' => 'Panel',
        ])->assertHasNoErrors()->assertNotified();

        $stored = Setting::get(PanelMail::SETTING);
        $this->assertNotSame('s3cret', $stored['password'], 'the password is kept encrypted');
        PanelMail::apply();
        $this->assertSame('panel_smtp', config('mail.default'));
        $this->assertSame('s3cret', config('mail.mailers.panel_smtp.password'));
        $this->assertSame('panel@example.com', config('mail.from.address'));
        Livewire::test(NotificationsPage::class)->assertSee('smtp.example.com')->assertDontSee('s3cret');

        // Saving again without a password keeps the stored one.
        $this->pageAction(Livewire::test(NotificationsPage::class), 'smtp', ['enabled' => true, 'host' => 'smtp2.example.com', 'port' => 465, 'encryption' => 'ssl', 'from_address' => 'panel@example.com']);
        PanelMail::apply();
        $this->assertSame('s3cret', config('mail.mailers.panel_smtp.password'));
        $this->assertSame('smtps', config('mail.mailers.panel_smtp.scheme'));

        $this->pageAction(Livewire::test(NotificationsPage::class), 'smtp', ['enabled' => false]);
        $this->assertFalse(PanelMail::enabled());
    }

    public function test_a_client_cannot_change_how_the_panel_sends_mail(): void
    {
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());
        Livewire::test(NotificationsPage::class)->assertDontSee('Odesílání e-mailů')->call('testMail');
        $this->pageAction(Livewire::test(NotificationsPage::class), 'smtp', ['enabled' => true, 'host' => 'evil.example.com', 'port' => 25, 'encryption' => 'none', 'from_address' => 'x@example.com']);
        $this->assertNull(PanelMail::get());
    }
}
