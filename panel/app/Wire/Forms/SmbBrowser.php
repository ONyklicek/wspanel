<?php

namespace App\Wire\Forms;

use App\Actions\Sites\ManageSiteMounts;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Closure;
use NyonCode\WireForms\Components\Button;
use NyonCode\WireForms\Components\Hidden;
use NyonCode\WireForms\Components\Select;

/**
 * Picking a share and a folder on an SMB server by looking, for someone who
 * does not know the server's layout: "Najít sdílené složky" asks the server
 * (agent smb.browse) what the account sees, a share is chosen from the list,
 * then a folder one level at a time, and the form says whether the account
 * may write there. The fields typed by hand stay, so it works without too.
 *
 * The form's own fields are named by the caller (a site's disk and the
 * off-site storage call them differently).
 */
final class SmbBrowser
{
    /**
     * @param  array{host: string, port: string, user: string, password: string, domain: string, encrypt: string, share: string, path: string}  $names
     * @param  Closure  $visible  fn ($get): bool — only for SMB
     * @return list<mixed>
     */
    public static function fields(array $names, Closure $visible): array
    {
        $connection = fn ($get, array $extra = []): array => [
            'host' => (string) $get($names['host']), 'port' => (int) $get($names['port']), 'user' => (string) $get($names['user']),
            'password' => (string) $get($names['password']), 'domain' => (string) $get($names['domain']), 'encrypt' => (bool) $get($names['encrypt']),
        ] + $extra;
        $decode = fn ($get, string $key): array => (array) (json_decode((string) $get($key), true) ?: []);
        $folders = function ($get, $set, string $share, string $path) use ($connection, $names): void {
            try {
                $out = app(ManageSiteMounts::class)->browse($connection($get, ['share' => $share, 'path' => $path]));
                $set('smb_dirs', json_encode($out['dirs'] ?? []));
                $set('smb_writable', ($out['writable'] ?? false) ? 'yes' : 'no');
                $set($names['path'], (string) ($out['path'] ?? $path));
            } catch (AgentException $e) {
                $set('smb_dirs', '[]');
                $set('smb_writable', '');
                Toast::error(__('Složky se nepodařilo načíst'), $e->getMessage());
            }
        };

        return [
            Hidden::make('smb_shares'),
            Hidden::make('smb_dirs'),
            Hidden::make('smb_writable'),
            Button::make('smb_browse')->label(__('Najít sdílené složky'))->icon('outline:magnifying-glass')->outlined()
                ->visible($visible)
                ->action(function ($get, $set) use ($connection, $names) {
                    if (trim((string) $get($names['host'])) === '' || trim((string) $get($names['user'])) === '') {
                        Toast::warning(__('Vyplňte server a uživatele'), __('Panel se pak serveru zeptá, co účet vidí.'));

                        return;
                    }
                    try {
                        $shares = app(ManageSiteMounts::class)->browse($connection($get))['shares'] ?? [];
                        $set('smb_shares', json_encode((object) collect($shares)->mapWithKeys(fn (array $sh) => [$sh['name'] => (string) ($sh['comment'] ?? '')])->all()));
                        $set('smb_dirs', '[]');
                        $set('smb_writable', '');
                        $shares === []
                            ? Toast::warning(__('Server žádnou sdílenou složku nenabízí'), __('Účet možná žádnou nevidí; zkontrolujte oprávnění na serveru.'))
                            : Toast::success(__('Nalezeno sdílených složek: :n', ['n' => count($shares)]), __('Vyberte jednu níže.'));
                    } catch (AgentException $e) {
                        Toast::error(__('Server se nepodařilo prohledat'), $e->getMessage());
                    }
                }),
            Select::make('smb_share_pick')->label(__('Sdílené složky na serveru'))->placeholder(__('vyberte…'))
                ->options(fn ($get = null) => $get === null ? [] : collect($decode($get, 'smb_shares'))->mapWithKeys(fn ($comment, $name) => [$name => $comment ? "{$name} – {$comment}" : $name])->all())
                ->visible(fn ($get = null) => $get !== null && $visible($get) && $decode($get, 'smb_shares') !== [])
                ->live()->dehydrated(false)
                ->afterStateUpdated(function ($state, $get, $set) use ($folders, $names) {
                    if (! is_string($state) || $state === '') {
                        return;
                    }
                    $set($names['share'], $state);
                    $folders($get, $set, $state, '');
                }),
            Select::make('smb_dir_pick')->label(__('Podsložky (vybraná složka je v poli Složka na disku)'))
                ->placeholder(__('otevřít složku…'))
                ->helperText(fn ($get = null) => $get === null ? null : ((string) $get('smb_writable') === 'yes'
                    ? __('Do této složky účet smí zapisovat.')
                    : __('Do této složky účet zapisovat nesmí (jen číst). Dejte mu na serveru právo zápisu, nebo disk připojte jen pro čtení.')))
                ->options(fn ($get = null) => $get === null ? [] : ($get($names['path']) ? ['..' => __('↑ o úroveň výš')] : [])
                    + collect($decode($get, 'smb_dirs'))->mapWithKeys(fn ($d) => [(string) $d => (string) $d])->all())
                ->visible(fn ($get = null) => $get !== null && $visible($get) && (string) $get('smb_writable') !== '')
                ->live()->dehydrated(false)
                ->afterStateUpdated(function ($state, $get, $set) use ($folders, $names) {
                    if (! is_string($state) || $state === '') {
                        return;
                    }
                    $path = trim((string) $get($names['path']), '/');
                    $path = $state === '..' ? (str_contains($path, '/') ? substr($path, 0, strrpos($path, '/')) : '') : ltrim($path.'/'.$state, '/');
                    $set('smb_dir_pick', null);
                    $folders($get, $set, (string) $get($names['share']), $path);
                }),
        ];
    }
}
