<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Models\MailAlias;
use App\Models\MailDomain;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Table;

/** Aliases and forwards of a mail domain (empty local part = catch-all). */
class MailAliasTable extends Component
{
    use WithTable;

    public int $domain;

    public function mailDomain(): MailDomain
    {
        return MailDomain::findOrFail($this->domain);
    }

    /** @param array<string, list<string>> $aliases */
    private function save(MailDomain $domain, array $aliases, string $ok): void
    {
        try {
            app(ManageMailHosting::class)->setAliases($domain, $aliases);
            Toast::success($ok);
        } catch (AgentException $e) {
            Toast::error('Aliasy se nepodařilo uložit', $e->getMessage());
        }
    }

    /** @return array<string, list<string>> */
    private function current(MailDomain $domain): array
    {
        return $domain->aliases->mapWithKeys(fn (MailAlias $a) => [$a->local_part => $a->destinations])->all();
    }

    public function table(Table $table): Table
    {
        $domain = $this->mailDomain();
        $form = [
            TextInput::make('local_part')->label('Adresa (před @'.$domain->domain.'; prázdné = vše ostatní, catch-all)')
                ->rules(['nullable', 'regex:/^[a-z0-9]([a-z0-9._+-]{0,62}[a-z0-9])?$/']),
            Textarea::make('destinations')->label('Doručit na (jedna adresa na řádek)')->rows(3)->required()
                ->rules([fn () => function (string $attr, mixed $value, \Closure $fail) {
                    foreach (preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
                        if (! filter_var($a, FILTER_VALIDATE_EMAIL)) {
                            $fail("{$a} není e-mailová adresa.");
                        }
                    }
                }]),
        ];
        $lines = fn (string $v) => array_values(array_unique(array_map('strtolower', preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [])));

        return $table
            ->stackedOnMobile()
            ->query(MailAlias::query()->where('mail_domain_id', $domain->id))
            ->columns([
                TextColumn::make('local_part')->label('Alias')->fontFamily('mono')->weight('medium')
                    ->formatStateUsing(fn ($state) => ($state === '' || $state === null ? '*' : $state).'@'.$domain->domain),
                TextColumn::make('destinations')->label('Doručuje na')->state(fn (MailAlias $r) => implode(', ', $r->destinations)),
            ])
            ->headerActions([
                Action::make('createAlias')->label('Nový alias / přeposílání')->icon('outline:plus')
                    ->form($form)
                    ->action(function (array $data) use ($domain, $lines) {
                        $aliases = $this->current($domain);
                        $aliases[strtolower(trim((string) ($data['local_part'] ?? '')))] = $lines((string) $data['destinations']);
                        $this->save($domain, $aliases, 'Alias uložen');
                    }),
            ])
            ->actions([
                Action::make('delete')->label('Smazat')->icon('outline:trash')->color('danger')->onlyIcon()->requiresConfirmation()
                    ->action(function (MailAlias $record) use ($domain) {
                        $aliases = $this->current($domain);
                        unset($aliases[$record->local_part]);
                        $this->save($domain, $aliases, 'Alias smazán');
                    }),
            ])
            ->emptyState('Žádné aliasy', 'Alias doručí poštu na jinou adresu (i mimo server), catch-all zachytí vše, co nemá schránku.', 'outline:arrows-right-left')
            ->searchable(false)
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.mail.alias-table');
    }
}
