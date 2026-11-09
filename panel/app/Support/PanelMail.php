<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * How the panel sends its own mail (notifications, password resets): the
 * server's Postfix by default, or an external SMTP server set on Účet →
 * Notifikace. The password is kept encrypted with the app key.
 */
final class PanelMail
{
    public const SETTING = 'mail.smtp';

    /** @return array{enabled: bool, host: string, port: int, encryption: string, username: string, password?: string, from_address: string, from_name: string}|null */
    public static function get(): ?array
    {
        $v = Setting::get(self::SETTING);

        return is_array($v) ? $v : null;
    }

    public static function enabled(): bool
    {
        return (bool) (self::get()['enabled'] ?? false);
    }

    /**
     * @param  array{host: string, port: int, encryption: string, username?: ?string, password?: ?string, from_address: string, from_name?: ?string}  $data  password null = keep
     */
    public static function save(array $data, bool $enabled = true): void
    {
        $old = self::get() ?? [];
        $password = $data['password'] ?? null;
        Setting::put(self::SETTING, [
            'enabled' => $enabled,
            'host' => (string) $data['host'],
            'port' => (int) $data['port'],
            'encryption' => (string) $data['encryption'],
            'username' => (string) ($data['username'] ?? ''),
            'password' => $password !== null && $password !== '' ? Crypt::encryptString($password) : ($old['password'] ?? ''),
            'from_address' => (string) $data['from_address'],
            'from_name' => (string) ($data['from_name'] ?? 'WSPanel'),
        ]);
    }

    public static function disable(): void
    {
        $v = self::get();
        if ($v !== null) {
            Setting::put(self::SETTING, ['enabled' => false] + $v);
        }
    }

    /** Points Laravel's mailer at the external server when it is on. */
    public static function apply(): void
    {
        try {
            $s = self::get();
        } catch (Throwable) {
            return; // no database yet (installation)
        }
        if (! ($s['enabled'] ?? false)) {
            return;
        }
        $password = '';
        try {
            $password = $s['password'] !== '' ? Crypt::decryptString($s['password']) : '';
        } catch (Throwable) {
        }
        config([
            'mail.default' => 'panel_smtp',
            'mail.mailers.panel_smtp' => [
                'transport' => 'smtp',
                'scheme' => $s['encryption'] === 'ssl' ? 'smtps' : 'smtp',
                'host' => $s['host'],
                'port' => $s['port'],
                'username' => $s['username'] !== '' ? $s['username'] : null,
                'password' => $password !== '' ? $password : null,
                // STARTTLS is required unless "none" was chosen (a server on a trusted network).
                'require_tls' => $s['encryption'] === 'tls',
                'auto_tls' => $s['encryption'] !== 'none',
                'timeout' => 20,
            ],
            'mail.from.address' => $s['from_address'],
            'mail.from.name' => $s['from_name'],
        ]);
    }
}
