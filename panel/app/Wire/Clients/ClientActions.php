<?php

namespace App\Wire\Clients;

use App\Actions\Clients\Impersonation;
use App\Actions\Clients\ManageClients;
use App\Enums\SshAccess;
use App\Models\Client;
use App\Models\Package;
use App\Models\User;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\ClientResource;
use App\Wire\Resources\PackageResource;
use Illuminate\Support\Facades\Auth;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Foundation\Schema\Grid;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use RuntimeException;

/** Actions on a client, shared by the client list and the client's page. */
final class ClientActions
{
    /** @return list<mixed> contact fields of the client form */
    private static function contactFields(): array
    {
        return [
            Grid::make()->columns(2)->schema([
                TextInput::make('name')->label('Jméno')->required()->maxLength(128)->rules(['max:128']),
                TextInput::make('company')->label('Firma')->maxLength(128)->rules(['max:128']),
                TextInput::make('email')->label('Kontaktní e-mail')->email()->rules(['nullable', 'email', 'max:255']),
                TextInput::make('phone')->label('Telefon')->maxLength(32)->rules(['max:32']),
            ]),
            Select::make('package_id')->label('Balíček')->rules([Options::rule()])
                ->options(fn () => Package::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder('bez balíčku (neomezeno)'),
            Textarea::make('notes')->label('Poznámka')->rows(2)->rules(['nullable', 'max:2000']),
        ];
    }

    public static function create(): Action
    {
        return Action::make('createClient')
            ->label('Nový klient')
            ->icon('outline:plus')
            ->modalHeading('Nový klient')
            ->modalWidth('2xl')
            ->form([
                ...self::contactFields(),
                Section::make('login')->label('Přihlášení do panelu')->schema([
                    Grid::make()->columns(2)->schema([
                        TextInput::make('login_email')->label('E-mail pro přihlášení')->email()->required()
                            ->rules(['email', 'max:255', 'unique:users,email']),
                        TextInput::make('login_password')->label('Heslo')->password()->required()->rules(['string', 'min:10']),
                    ]),
                ]),
            ])
            ->action(function (array $data, $component) {
                $client = app(ManageClients::class)->create($data);
                Toast::success("Klient {$client->name} založen", 'Přihlásí se e-mailem '.$data['login_email'].'.');
                $component->redirect(ClientResource::url('view', $client));
            });
    }

    public static function edit(): Action
    {
        return Action::make('editClient')
            ->label('Upravit údaje')
            ->icon('outline:pencil-square')
            ->modalHeading(fn (Client $record) => "Klient {$record->name}")
            ->modalWidth('2xl')
            ->fillFormUsing(fn (Client $record) => $record->only(['name', 'company', 'email', 'phone', 'package_id', 'notes']))
            ->form(self::contactFields())
            ->action(function (Client $record, array $data) {
                app(ManageClients::class)->update($record, $data);
                Toast::success('Údaje uloženy');
            });
    }

    /** Per-client limits that differ from the package. */
    public static function limits(): Action
    {
        return Action::make('limits')
            ->label('Limity klienta')
            ->icon('outline:adjustments-horizontal')
            ->modalHeading(fn (Client $record) => "Limity: {$record->name}")
            ->modalDescription('Prázdné pole = jak říká balíček. Číslo nebo ∞ (neomezeno) platí jen pro tohoto klienta a přebije balíček.')
            ->modalWidth('4xl')
            ->fillFormUsing(function (Client $record) {
                $values = [];
                foreach ($record->overrides ?? [] as $column => $value) {
                    $values[$column] = $column === 'ssh' ? $value : ($value === null ? '∞' : (string) $value);
                }

                return $values;
            })
            ->form(function (Client $record) {
                $fields = [];
                foreach (PackageResource::NUMERIC as $column => $label) {
                    $fields[] = TextInput::make($column)->label($label)
                        ->placeholder('podle balíčku: '.self::show($record->package?->getAttribute($column), $record->package !== null))
                        ->rules(['nullable', 'regex:/^(\d{1,8}|∞)$/u'])
                        ->validationMessages(['regex' => 'Číslo, nebo ∞ pro neomezeno.']);
                }

                return [
                    Grid::make()->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema($fields),
                    Select::make('ssh')->label('SSH / SFTP')->rules([Options::rule()])
                        ->placeholder('podle balíčku: '.$record->package?->ssh?->getLabel())
                        ->options(collect(SshAccess::cases())->mapWithKeys(fn (SshAccess $a) => [$a->value => $a->getLabel()])->all()),
                ];
            })
            ->action(function (Client $record, array $data) {
                $overrides = [];
                foreach (array_keys(PackageResource::NUMERIC) as $column) {
                    $value = trim((string) ($data[$column] ?? ''));
                    if ($value !== '') {
                        $overrides[$column] = $value === '∞' ? null : (int) $value;
                    }
                }
                if (filled($data['ssh'] ?? null)) {
                    $overrides['ssh'] = $data['ssh'];
                }
                app(ManageClients::class)->setOverrides($record, $overrides);
                Toast::success('Limity uloženy');
            });
    }

    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label('Pozastavit')
            ->icon('outline:pause-circle')
            ->color('danger')
            ->visible(fn (Client $record) => ! $record->isSuspended())
            ->requiresConfirmation()
            ->modalHeading(fn (Client $record) => "Pozastavit klienta {$record->name}?")
            ->modalDescription('Jeho běžící weby se vypnou (návštěvníci uvidí 503) a nepřihlásí se do panelu. Data zůstanou, obnovení vrátí vše, jak bylo.')
            ->action(function (Client $record) {
                $failed = app(ManageClients::class)->suspend($record);
                $failed === [] ? Toast::success('Klient pozastaven') : Toast::warning('Klient pozastaven, některé weby se nevypnuly', implode("\n", $failed));
            });
    }

    public static function resume(): Action
    {
        return Action::make('resume')
            ->label('Obnovit')
            ->icon('outline:play-circle')
            ->color('success')
            ->visible(fn (Client $record) => $record->isSuspended())
            ->requiresConfirmation()
            ->action(function (Client $record) {
                $failed = app(ManageClients::class)->resume($record);
                $failed === [] ? Toast::success('Klient obnoven') : Toast::warning('Klient obnoven, některé weby se nezapnuly', implode("\n", $failed));
            });
    }

    public static function addUser(): Action
    {
        return Action::make('addUser')
            ->label('Přidat přihlášení')
            ->icon('outline:user-plus')
            ->modalHeading(fn (Client $record) => "Nové přihlášení pro {$record->name}")
            ->form([
                TextInput::make('name')->label('Jméno')->required()->rules(['max:255']),
                TextInput::make('email')->label('E-mail')->email()->required()->rules(['email', 'max:255', 'unique:users,email']),
                TextInput::make('password')->label('Heslo')->password()->required()->rules(['string', 'min:10']),
            ])
            ->action(function (Client $record, array $data) {
                app(ManageClients::class)->addUser($record, $data['name'], $data['email'], $data['password']);
                Toast::success('Přihlášení přidáno', $data['email']);
            });
    }

    /** Look at the panel as the client's first user. */
    public static function impersonate(): Action
    {
        return Action::make('impersonate')
            ->label('Přihlásit se jako klient')
            ->icon('outline:eye')
            ->visible(fn (Client $record) => $record->users()->exists())
            ->action(function (Client $record, $component) {
                $user = $record->users()->orderBy('id')->first();
                $admin = Auth::user();
                if (! $user instanceof User || ! $admin instanceof User) {
                    return;
                }
                app(Impersonation::class)->start($admin, $user);
                $component->redirect('/');
            });
    }

    public static function delete(): Action
    {
        return Action::make('deleteClient')
            ->label('Smazat klienta')
            ->icon('outline:trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Client $record) => "Smazat klienta {$record->name}?")
            ->modalDescription('Smaže klienta a jeho přihlášení. Jde to, jen když už nevlastní žádný web, databázi ani DNS zónu.')
            ->action(function (Client $record, $component) {
                try {
                    app(ManageClients::class)->delete($record);
                    Toast::success('Klient smazán');
                    $component->redirect(ClientResource::url('index'));
                } catch (RuntimeException $e) {
                    Toast::error('Klienta nelze smazat', $e->getMessage());
                }
            });
    }

    /** @return list<Action> */
    public static function more(): array
    {
        return [self::edit(), self::limits(), self::addUser(), self::impersonate(), self::suspend(), self::resume(), self::delete()];
    }

    public static function show(mixed $value, bool $hasPackage = true): string
    {
        return ! $hasPackage || $value === null ? '∞' : (string) $value;
    }
}
