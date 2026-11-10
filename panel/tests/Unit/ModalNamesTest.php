<?php

namespace Tests\Unit;

use App\Wire\Actions\Action;
use NyonCode\WireForms\Components\TextInput;
use Tests\TestCase;

/** A modal says what it does, not "Potvrdit akci" / "Potvrdit". */
class ModalNamesTest extends TestCase
{
    public function test_a_confirmation_is_headed_and_confirmed_by_the_action_itself(): void
    {
        $action = Action::make('restart')->label('Restartovat')->requiresConfirmation();

        $this->assertSame('Restartovat', $action->getModalHeading());
        $this->assertSame('Restartovat', $action->getModalSubmitActionLabel());
    }

    public function test_settings_save_a_new_thing_is_created_an_operation_says_itself(): void
    {
        $this->assertSame('Uložit', Action::make('spam')->label('Antispam')->form([TextInput::make('level')])->getModalSubmitActionLabel());
        $this->assertSame('Uložit', Action::make('schedule')->label('Plán záloh')->form([TextInput::make('keep')])->getModalSubmitActionLabel());
        $this->assertSame('Vytvořit', Action::make('create')->label('Nový server')->form([TextInput::make('name')])->getModalSubmitActionLabel());
        $this->assertSame('Plán záloh', Action::make('schedule')->label('Plán záloh')->form([TextInput::make('keep')])->getModalHeading());
        $this->assertSame('Aktualizovat', Action::make('upgrade')->label('Aktualizovat')->form([TextInput::make('version')])->getModalSubmitActionLabel());
    }

    public function test_an_own_heading_and_button_stay(): void
    {
        $action = Action::make('drop')->label('Smazat')->modalHeading('Smazat databázi shop?')->modalSubmitActionLabel('Smazat natrvalo')->requiresConfirmation();

        $this->assertSame('Smazat databázi shop?', $action->getModalHeading());
        $this->assertSame('Smazat natrvalo', $action->getModalSubmitActionLabel());
    }
}
