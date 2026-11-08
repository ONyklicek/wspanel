<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsPrerequisites;
use App\Support\Toast;
use App\Wire\Forms\DnsGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Tags;
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
                TextColumn::make('local_part')->label(__('Schránka'))->weight('medium')->fontFamily('mono')
                    ->formatStateUsing(fn ($state) => $state.'@'.$domain->domain)->searchable(),
                TextColumn::make('used')->label(__('Obsazeno'))
                    ->state(fn (Mailbox $r) => ($r->used_kb !== null ? Number::fileSize($r->used_kb * 1024, 1) : '—').' z '.Number::fileSize($r->quota_mb * 1048576, 0)),
                TextColumn::make('forward')->label(__('Přesměrování'))
                    ->state(fn (Mailbox $r) => $r->forward ? '→ '.implode(', ', $r->forward['to']).($r->forward['keep_copy'] ? __(' (kopie zůstává)') : __(' (bez kopie)')) : '—'),
                BadgeColumn::make('state')->label(__('Stav'))
                    ->state(fn (Mailbox $r) => $r->enabled ? ($r->autoreply ? __('aktivní · autoodpověď') : __('aktivní')) : __('zablokovaná'))
                    ->colorUsing(fn ($state) => str_starts_with((string) $state, __('aktivní')) ? 'success' : 'gray'),
            ])
            ->headerActions([
                Action::make('createMailbox')
                    ->label(__('Nová schránka'))
                    ->icon('outline:plus')
                    ->form([
                        TextInput::make('local_part')->label(__('Adresa (před @').$domain->domain.')')->required()
                            ->rules(['regex:/^[a-z0-9]([a-z0-9._+-]{0,62}[a-z0-9])?$/'])
                            ->validationMessages(['regex' => __('Malá písmena, číslice a . _ + -')]),
                        TextInput::make('password')->label(__('Heslo'))->password()->revealable()
                            ->helperText(__('Prázdné = panel vygeneruje a jednou ukáže.'))->rules(['nullable', 'string', 'min:10', 'max:200']),
                        TextInput::make('quota_mb')->label(__('Velikost (MB)'))->integer()->default(1024)->required()->rules(['integer', 'between:10,1048576']),
                    ])
                    ->action(fn (array $data) => $this->attempt(__('Schránku se nepodařilo založit'), function () use ($data, $domain) {
                        $password = filled($data['password'] ?? null) ? $data['password'] : ManageMailHosting::randomPassword();
                        $mb = app(ManageMailHosting::class)->saveMailbox($domain, $data['local_part'], $password, (int) $data['quota_mb'], true);
                        filled($data['password'] ?? null)
                            ? Toast::success(__('Schránka založena'), $mb->address())
                            : Toast::secret(__('Schránka ').$mb->address().__(' založena'), __('Heslo (zobrazeno jen teď): ').$password);
                    })),
            ])
            ->actions([
                Action::make('webmail')->label(__('Webmail'))->icon('outline:envelope-open')
                    ->url(fn (Mailbox $record) => route('mailboxes.webmail', $record), openInNewTab: true)
                    ->visible(fn (Mailbox $record) => $domain->webmail && $record->enabled),
                ActionGroup::make([
                    Action::make('appleProfile')->label(__('Profil pro iPhone a Mac'))->icon('outline:device-phone-mobile')
                        ->url(fn (Mailbox $record) => route('mailboxes.apple-profile', $record)),
                    Action::make('password')->label(__('Nové heslo'))->icon('outline:key')
                        ->form([TextInput::make('password')->label(__('Heslo'))->password()->revealable()->helperText(__('Prázdné = vygenerovat.'))->rules(['nullable', 'string', 'min:10', 'max:200'])])
                        ->action(fn (Mailbox $record, array $data) => $this->attempt(__('Heslo se nepodařilo změnit'), function () use ($record, $data, $domain) {
                            $password = filled($data['password'] ?? null) ? $data['password'] : ManageMailHosting::randomPassword();
                            app(ManageMailHosting::class)->saveMailbox($domain, $record->local_part, $password, $record->quota_mb, $record->enabled, $record);
                            filled($data['password'] ?? null) ? Toast::success(__('Heslo změněno')) : Toast::secret(__('Nové heslo ').$record->address(), $password);
                        })),
                    Action::make('settings')->label(__('Velikost a stav'))->icon('outline:adjustments-horizontal')
                        ->fillFormUsing(fn (Mailbox $record) => ['quota_mb' => $record->quota_mb, 'enabled' => $record->enabled])
                        ->form([
                            TextInput::make('quota_mb')->label(__('Velikost (MB)'))->integer()->required()->rules(['integer', 'between:10,1048576']),
                            Toggle::make('enabled')->label(__('Přihlášení povoleno (pošta chodí vždy)')),
                        ])
                        ->action(fn (Mailbox $record, array $data) => $this->attempt(__('Nastavení se nepodařilo uložit'), function () use ($record, $data, $domain) {
                            app(ManageMailHosting::class)->saveMailbox($domain, $record->local_part, null, (int) $data['quota_mb'], (bool) $data['enabled'], $record);
                            Toast::success(__('Uloženo'));
                        })),
                    Action::make('forward')->label(__('Přesměrování'))->icon('outline:arrow-uturn-right')
                        ->modalDescription(__('Příchozí pošta schránky se přepošle na zadané adresy: jinou schránku nebo kamkoli jinam.'))
                        ->fillFormUsing(fn (Mailbox $record) => ['to' => $record->forward['to'] ?? [], 'keep_copy' => ($record->forward['keep_copy'] ?? true) ? '1' : '0'])
                        ->form([
                            Tags::make('to')->label(__('Přeposílat na'))
                                ->suggestions(fn () => Mailbox::query()->with('mailDomain')->get()->map->address()->sort()->values()->all())
                                ->helperText(__('Prázdné = přesměrování vypnuto.'))
                                ->rules(['array', 'max:50'])->live(),
                            Radio::make('keep_copy')->label(__('Ponechat kopii ve schránce'))
                                ->options(['1' => __('Ano'), '0' => __('Ne')])
                                ->descriptions(['1' => __('Zprávy zůstanou i v této schránce.'), '0' => __('Zprávy se jen přepošlou, schránka zůstane prázdná (neběží ani její automatická odpověď).')])
                                ->rules(['in:0,1'])->default('1'),
                            ...DnsGate::fields(fn (DnsPrerequisites $dns, callable $get) => $dns->forwardingSpf((array) $get('to'))),
                        ])
                        ->action(fn (Mailbox $record, array $data) => $this->attempt(__('Přesměrování se nepodařilo nastavit'), function () use ($record, $data) {
                            $to = array_values(array_unique(array_map(fn ($a) => strtolower(trim((string) $a)), (array) ($data['to'] ?? []))));
                            foreach ($to as $address) {
                                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                                    throw new AgentException('invalid', __(':address není e-mailová adresa.', ['address' => $address]), 'panel');
                                }
                            }
                            if (in_array($record->address(), $to, true)) {
                                throw new AgentException('invalid', __('Schránka nemůže přeposílat sama sobě.'), 'panel');
                            }
                            app(ManageMailHosting::class)->forward($record, $to, (string) ($data['keep_copy'] ?? '1') === '1');
                            Toast::success($to === [] ? __('Přesměrování vypnuto') : __('Pošta se přeposílá na ').implode(', ', $to));
                        })),
                    Action::make('autoreply')->label(__('Automatická odpověď'))->icon('outline:paper-airplane')
                        ->fillFormUsing(fn (Mailbox $record) => ['enabled' => $record->autoreply !== null] + ($record->autoreply ?? ['subject' => __('Nejsem k zastižení'), 'body' => '', 'days' => 1]))
                        ->form([
                            Toggle::make('enabled')->label(__('Zapnout'))->live(),
                            TextInput::make('subject')->label(__('Předmět'))->visibleWhen('enabled', true)->requiredIf('enabled', true)->rules(['nullable', 'max:200']),
                            Textarea::make('body')->label(__('Text'))->rows(5)->visibleWhen('enabled', true)->requiredIf('enabled', true)->rules(['nullable', 'max:4000']),
                            TextInput::make('days')->label(__('Jednomu odesílateli nejvýš jednou za (dní)'))->integer()->default(1)->visibleWhen('enabled', true)->rules(['nullable', 'integer', 'between:1,30']),
                        ])
                        ->action(fn (Mailbox $record, array $data) => $this->attempt(__('Odpověď se nepodařilo nastavit'), function () use ($record, $data) {
                            app(ManageMailHosting::class)->autoreply($record, $data);
                            Toast::success($data['enabled'] ? __('Automatická odpověď zapnuta') : __('Automatická odpověď vypnuta'));
                        })),
                    Action::make('delete')->label(__('Smazat schránku'))->icon('outline:trash')->color('danger')
                        ->requiresConfirmation()->modalDescription(__('Smaže schránku i všechny zprávy v ní.'))
                        ->action(fn (Mailbox $record) => $this->attempt(__('Schránku se nepodařilo smazat'), function () use ($record) {
                            app(ManageMailHosting::class)->deleteMailbox($record);
                            Toast::success(__('Schránka smazána'));
                        })),
                ]),
            ])
            ->emptyState(__('Zatím žádná schránka'), __('Založte první schránku tlačítkem Nová schránka.'), 'outline:inbox')
            ->searchable(false)
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.mail.mailbox-table');
    }
}
