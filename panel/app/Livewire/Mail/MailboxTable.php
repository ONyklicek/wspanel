<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Livewire\Concerns\RefreshesWhenTasksEnd;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsPrerequisites;
use App\Support\AgentTasks;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Forms\DnsGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Component;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Actions\ModalFooterAction;
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
    use RefreshesWhenTasksEnd;
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
                TextColumn::make('external')->label(__('Externí schránka'))
                    ->state(fn (Mailbox $r) => $this->externalState($r))
                    ->visible(fn () => Mailbox::query()->where('mail_domain_id', $domain->id)->whereNotNull('external')->exists()),
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
                        // Not a hard stop: mailboxes are made before MX moves when mail is migrated.
                        ...DnsGate::fields(fn (DnsPrerequisites $dns) => $dns->mailboxes($domain->domain),
                            __('Založit i tak: DNS nastavím (nebo převádím poštu odjinud a MX přepnu až po přenosu)')),
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
                    $this->externalAction(),
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

    /** "↓ imap.provider.cz · před 2 minutami", or what went wrong; "↑ smtp…" when it sends there. */
    private function externalState(Mailbox $mailbox): string
    {
        $parts = [];
        if ($fetch = $mailbox->external['fetch'] ?? null) {
            $status = app(ManageMailHosting::class)->fetchStatus($mailbox->mailDomain)[$mailbox->address()] ?? null;
            $parts[] = '↓ '.$fetch['host'].' · '.match (true) {
                $status === null => __('čeká na první stažení'),
                ! $status['ok'] => __('stažení selhalo: :error', ['error' => $status['error'] ?? '?']),
                default => __('staženo :when', ['when' => Carbon::parse($status['at'])->diffForHumans()])
                    .(isset($status['last_mail']) ? __(', poslední pošta :when', ['when' => Carbon::parse($status['last_mail'])->diffForHumans()]) : ''),
            };
        }
        if ($relay = $mailbox->external['relay'] ?? null) {
            $parts[] = '↑ '.__('odesílá přes :host', ['host' => $relay['host']]);
        }

        return $parts === [] ? '—' : implode("\n", $parts);
    }

    /**
     * A provider's mailbox (too small there) downloaded into this one every
     * two minutes and removed there after some days; what this one sends
     * leaves through the provider's SMTP, its copy staying in Sent here.
     */
    private function externalAction(): Action
    {
        $fetchFields = fn (array $data): ?array => ($data['fetch_on'] ?? false) ? [
            'protocol' => (string) $data['protocol'], 'host' => (string) $data['host'], 'port' => (int) ($data['port'] ?? 0) ?: null,
            'user' => (string) $data['user'], 'password' => (string) ($data['password'] ?? ''), 'keep_days' => (int) ($data['keep_days'] ?? 7),
        ] : null;
        $relayFields = fn (array $data): ?array => ($data['relay_on'] ?? false) ? [
            'host' => (string) $data['relay_host'], 'port' => (int) ($data['relay_port'] ?? 587),
            'user' => ($data['relay_same'] ?? true) && ($data['fetch_on'] ?? false) ? (string) $data['user'] : (string) ($data['relay_user'] ?? ''),
            'password' => ($data['relay_same'] ?? true) && ($data['fetch_on'] ?? false) ? (string) ($data['password'] ?? '') : (string) ($data['relay_password'] ?? ''),
        ] : null;

        return Action::make('external')->label(__('Externí schránka'))->icon('outline:cloud-arrow-down')
            ->busyWhile('mail.external')
            ->modalDescription(__('Schránka u jiného poskytovatele (třeba s malým limitem): její pošta se sem každé 2 minuty stáhne a u poskytovatele se po nastaveném počtu dní smaže, takže jeho limit přestane vadit. MX domény zůstává u poskytovatele.'))
            ->fillFormUsing(fn (Mailbox $record) => [
                'fetch_on' => isset($record->external['fetch']), 'protocol' => $record->external['fetch']['protocol'] ?? 'imap',
                'host' => $record->external['fetch']['host'] ?? '', 'port' => $record->external['fetch']['port'] ?? null,
                'user' => $record->external['fetch']['user'] ?? $record->address(), 'keep_days' => $record->external['fetch']['keep_days'] ?? 7,
                'relay_on' => isset($record->external['relay']), 'relay_host' => $record->external['relay']['host'] ?? '',
                'relay_port' => (string) ($record->external['relay']['port'] ?? 587),
                'relay_same' => ! isset($record->external['relay']) || ($record->external['relay']['user'] ?? '') === ($record->external['fetch']['user'] ?? null),
                'relay_user' => $record->external['relay']['user'] ?? '',
            ])
            ->form([
                Toggle::make('fetch_on')->label(__('Stahovat poštu z externí schránky'))->live(),
                Radio::make('protocol')->label(__('Protokol'))->options(['imap' => 'IMAP', 'pop3' => 'POP3'])
                    ->descriptions(['imap' => __('Doporučeno: jen doručená pošta, šifrovaně (obvykle port 993).'), 'pop3' => __('Šifrovaně, obvykle port 995.')])
                    ->default('imap')->visibleWhen('fetch_on', true)->rules(['nullable', 'in:imap,pop3']),
                TextInput::make('host')->label(__('Server, ze kterého se stahuje'))->placeholder('imap.poskytovatel.cz')
                    ->visibleWhen('fetch_on', true)->requiredIf('fetch_on', true)->rules(['nullable', 'max:253', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/']),
                // Another port (and a server in the local network): the administrator's only.
                TextInput::make('port')->label(__('Port'))->integer()->placeholder(fn ($get = null) => $get !== null && $get('protocol') === 'pop3' ? '995' : '993')
                    ->visible(fn ($get = null) => $get !== null && $get('fetch_on') && Gate::allows('admin'))->rules(['nullable', 'integer', 'between:1,65535']),
                TextInput::make('user')->label(__('Přihlašovací jméno u poskytovatele'))
                    ->visibleWhen('fetch_on', true)->requiredIf('fetch_on', true)->rules(['nullable', 'max:256', 'regex:/^[A-Za-z0-9._@+=-]+$/']),
                TextInput::make('password')->label(__('Heslo k účtu u poskytovatele'))->password()->revealable()
                    ->helperText(__('Prázdné = ponechat uložené heslo. Při změně serveru, portu nebo jména je potřeba zadat heslo znovu.'))
                    ->visibleWhen('fetch_on', true)->rules(['nullable', 'string', 'max:256', 'not_regex:/[\\x00-\\x1f\\x7f]/']),
                TextInput::make('keep_days')->label(__('Smazat u poskytovatele po (počet dní)'))->integer()->default(7)
                    ->helperText(__('Stažená pošta zůstane u poskytovatele ještě tolik dní jako pojistka. 0 = smazat hned po stažení.'))
                    ->visibleWhen('fetch_on', true)->rules(['nullable', 'integer', 'between:0,365']),
                Toggle::make('relay_on')->label(__('Odesílat přes SMTP poskytovatele'))->live()
                    ->helperText(__('Pošta z této schránky (z poštovního programu i webmailu) odejde přes poskytovatele, odeslané zprávy zůstanou tady.')),
                TextInput::make('relay_host')->label(__('SMTP server poskytovatele (odchozí pošta)'))->placeholder('smtp.poskytovatel.cz')
                    ->visibleWhen('relay_on', true)->requiredIf('relay_on', true)->rules(['nullable', 'max:253', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/']),
                Radio::make('relay_port')->label(__('Port'))->options(['587' => '587 (STARTTLS)', '465' => '465 (TLS)'])
                    ->default('587')->visibleWhen('relay_on', true)->rules(['nullable', 'in:587,465']),
                Toggle::make('relay_same')->label(__('Stejné přihlášení jako u stahování'))->default(true)->live()
                    ->visible(fn ($get = null) => $get !== null && $get('relay_on') && $get('fetch_on')),
                TextInput::make('relay_user')->label(__('Přihlašovací jméno pro SMTP'))
                    ->visible(fn ($get = null) => $get !== null && $get('relay_on') && (! $get('fetch_on') || ! $get('relay_same')))
                    ->rules(['nullable', 'max:256', 'regex:/^[A-Za-z0-9._@+=-]+$/']),
                TextInput::make('relay_password')->label(__('Heslo pro odesílání (SMTP)'))->password()->revealable()
                    ->helperText(__('Prázdné = ponechat uložené heslo. Při změně serveru, portu nebo jména je potřeba zadat heslo znovu.'))
                    ->visible(fn ($get = null) => $get !== null && $get('relay_on') && (! $get('fetch_on') || ! $get('relay_same')))
                    ->rules(['nullable', 'string', 'max:256', 'not_regex:/[\\x00-\\x1f\\x7f]/']),
            ])
            ->modalFooterActions([
                ModalFooterAction::make('testExternal')->label(__('Vyzkoušet spojení'))->icon('outline:signal')->color('gray')->position('before')
                    ->action(function (array $data, ?Mailbox $record = null) use ($fetchFields, $relayFields) {
                        $fetch = $fetchFields($data);
                        $relay = $relayFields($data);
                        if ($record === null || ($fetch === null && $relay === null)) {
                            Toast::warning(__('Není co zkoušet'), __('Zapněte stahování nebo odesílání a vyplňte server.'));

                            return;
                        }
                        try {
                            $out = app(ManageMailHosting::class)->testExternal($record, $fetch, $relay, Gate::allows('admin'));
                        } catch (AgentException $e) {
                            Toast::error(__('Nastavení není v pořádku'), $e->getMessage());

                            return;
                        }
                        $lines = [];
                        foreach (['fetch' => __('Stahování'), 'relay' => __('Odesílání')] as $key => $label) {
                            if (isset($out[$key])) {
                                $lines[] = $label.': '.($out[$key]['ok'] ? __('přihlášení funguje') : $out[$key]['error']);
                            }
                        }
                        collect($out)->every(fn ($r) => $r['ok'])
                            ? Toast::success(__('Spojení funguje'), implode("\n", $lines))
                            : Toast::error(__('Spojení nefunguje'), implode("\n", $lines));
                    }),
            ])
            ->action(function (Mailbox $record, array $data) use ($fetchFields, $relayFields) {
                $fetch = $fetchFields($data);
                $relay = $relayFields($data);
                AgentTasks::queue('mail.external', [
                    'title' => __('Externí schránka :address', ['address' => $record->address()]),
                    'done' => $fetch !== null ? __('Pošta :address se stahuje', ['address' => $record->address()]) : __('Externí schránka :address uložena', ['address' => $record->address()]),
                ], ManageMailHosting::class, 'setExternal', [$record, $fetch, $relay, Gate::allows('admin')], $record);
            });
    }

    public function render(): View
    {
        return view('livewire.mail.mailbox-table');
    }
}
