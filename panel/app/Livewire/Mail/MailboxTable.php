<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Table;

/** The mailboxes of a mail domain. */
class MailboxTable extends Component
{
    use WithTable;

    public int $domain;

    public function mailDomain(): MailDomain
    {
        return MailDomain::findOrFail($this->domain);
    }

    private function attempt(string $failure, callable $fn): void
    {
        try {
            $fn();
        } catch (AgentException $e) {
            Toast::error($failure, $e->getMessage());
        }
    }

    public function table(Table $table): Table
    {
        $domain = $this->mailDomain();

        return $table
            ->stackedOnMobile()
            ->query(Mailbox::query()->where('mail_domain_id', $domain->id))
            ->columns([
                TextColumn::make('local_part')->label('Schránka')->weight('medium')->fontFamily('mono')
                    ->formatStateUsing(fn ($state) => $state.'@'.$domain->domain)->searchable(),
                TextColumn::make('used')->label('Obsazeno')
                    ->state(fn (Mailbox $r) => ($r->used_kb !== null ? Number::fileSize($r->used_kb * 1024, 1) : '—').' z '.Number::fileSize($r->quota_mb * 1048576, 0)),
                BadgeColumn::make('state')->label('Stav')
                    ->state(fn (Mailbox $r) => $r->enabled ? ($r->autoreply ? 'aktivní · autoodpověď' : 'aktivní') : 'zablokovaná')
                    ->colorUsing(fn ($state) => str_starts_with((string) $state, 'aktivní') ? 'success' : 'gray'),
            ])
            ->headerActions([
                Action::make('createMailbox')
                    ->label('Nová schránka')
                    ->icon('outline:plus')
                    ->form([
                        TextInput::make('local_part')->label('Adresa (před @'.$domain->domain.')')->required()
                            ->rules(['regex:/^[a-z0-9]([a-z0-9._+-]{0,62}[a-z0-9])?$/'])
                            ->validationMessages(['regex' => 'Malá písmena, číslice a . _ + -']),
                        TextInput::make('password')->label('Heslo')->password()->revealable()
                            ->helperText('Prázdné = panel vygeneruje a jednou ukáže.')->rules(['nullable', 'string', 'min:10', 'max:200']),
                        TextInput::make('quota_mb')->label('Velikost (MB)')->integer()->default(1024)->required()->rules(['integer', 'between:10,1048576']),
                    ])
                    ->action(fn (array $data) => $this->attempt('Schránku se nepodařilo založit', function () use ($data, $domain) {
                        $password = filled($data['password'] ?? null) ? $data['password'] : ManageMailHosting::randomPassword();
                        $mb = app(ManageMailHosting::class)->saveMailbox($domain, $data['local_part'], $password, (int) $data['quota_mb'], true);
                        filled($data['password'] ?? null)
                            ? Toast::success('Schránka založena', $mb->address())
                            : Toast::secret('Schránka '.$mb->address().' založena', 'Heslo (zobrazeno jen teď): '.$password);
                    })),
            ])
            ->actions([
                Action::make('webmail')->label('Webmail')->icon('outline:envelope-open')
                    ->url(fn (Mailbox $record) => route('mailboxes.webmail', $record), openInNewTab: true)
                    ->visible(fn (Mailbox $record) => $domain->webmail && $record->enabled),
                ActionGroup::make([
                    Action::make('appleProfile')->label('Profil pro iPhone a Mac')->icon('outline:device-phone-mobile')
                        ->url(fn (Mailbox $record) => route('mailboxes.apple-profile', $record)),
                    Action::make('password')->label('Nové heslo')->icon('outline:key')
                        ->form([TextInput::make('password')->label('Heslo')->password()->revealable()->helperText('Prázdné = vygenerovat.')->rules(['nullable', 'string', 'min:10', 'max:200'])])
                        ->action(fn (Mailbox $record, array $data) => $this->attempt('Heslo se nepodařilo změnit', function () use ($record, $data, $domain) {
                            $password = filled($data['password'] ?? null) ? $data['password'] : ManageMailHosting::randomPassword();
                            app(ManageMailHosting::class)->saveMailbox($domain, $record->local_part, $password, $record->quota_mb, $record->enabled, $record);
                            filled($data['password'] ?? null) ? Toast::success('Heslo změněno') : Toast::secret('Nové heslo '.$record->address(), $password);
                        })),
                    Action::make('settings')->label('Velikost a stav')->icon('outline:adjustments-horizontal')
                        ->fillFormUsing(fn (Mailbox $record) => ['quota_mb' => $record->quota_mb, 'enabled' => $record->enabled])
                        ->form([
                            TextInput::make('quota_mb')->label('Velikost (MB)')->integer()->required()->rules(['integer', 'between:10,1048576']),
                            Toggle::make('enabled')->label('Přihlášení povoleno (pošta chodí vždy)'),
                        ])
                        ->action(fn (Mailbox $record, array $data) => $this->attempt('Nastavení se nepodařilo uložit', function () use ($record, $data, $domain) {
                            app(ManageMailHosting::class)->saveMailbox($domain, $record->local_part, null, (int) $data['quota_mb'], (bool) $data['enabled'], $record);
                            Toast::success('Uloženo');
                        })),
                    Action::make('autoreply')->label('Automatická odpověď')->icon('outline:paper-airplane')
                        ->fillFormUsing(fn (Mailbox $record) => ['enabled' => $record->autoreply !== null] + ($record->autoreply ?? ['subject' => 'Nejsem k zastižení', 'body' => '', 'days' => 1]))
                        ->form([
                            Toggle::make('enabled')->label('Zapnout')->live(),
                            TextInput::make('subject')->label('Předmět')->visibleWhen('enabled', true)->requiredIf('enabled', true)->rules(['nullable', 'max:200']),
                            Textarea::make('body')->label('Text')->rows(5)->visibleWhen('enabled', true)->requiredIf('enabled', true)->rules(['nullable', 'max:4000']),
                            TextInput::make('days')->label('Jednomu odesílateli nejvýš jednou za (dní)')->integer()->default(1)->visibleWhen('enabled', true)->rules(['nullable', 'integer', 'between:1,30']),
                        ])
                        ->action(fn (Mailbox $record, array $data) => $this->attempt('Odpověď se nepodařilo nastavit', function () use ($record, $data) {
                            app(ManageMailHosting::class)->autoreply($record, $data);
                            Toast::success($data['enabled'] ? 'Automatická odpověď zapnuta' : 'Automatická odpověď vypnuta');
                        })),
                    Action::make('delete')->label('Smazat schránku')->icon('outline:trash')->color('danger')
                        ->requiresConfirmation()->modalDescription('Smaže schránku i všechny zprávy v ní.')
                        ->action(fn (Mailbox $record) => $this->attempt('Schránku se nepodařilo smazat', function () use ($record) {
                            app(ManageMailHosting::class)->deleteMailbox($record);
                            Toast::success('Schránka smazána');
                        })),
                ]),
            ])
            ->emptyState('Zatím žádná schránka', 'Založte první schránku tlačítkem Nová schránka.', 'outline:inbox')
            ->searchable(false)
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.mail.mailbox-table');
    }
}
