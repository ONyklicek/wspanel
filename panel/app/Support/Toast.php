<?php

namespace App\Support;

use NyonCode\WireCore\Notifications\Notification;
use NyonCode\WireCore\Notifications\NotificationManager;

/** Toasts from anywhere in the panel (actions, pages, Livewire components). */
final class Toast
{
    public static function success(string $title, ?string $body = null): void
    {
        self::send(Notification::success($body ?? $title)->title($body === null ? null : $title));
    }

    public static function error(string $title, ?string $body = null): void
    {
        self::send(Notification::error($body ?? $title)->title($body === null ? null : $title)->persistent());
    }

    public static function warning(string $title, ?string $body = null): void
    {
        self::send(Notification::warning($body ?? $title)->title($body === null ? null : $title));
    }

    /** Something to copy (a password shown once): stays until closed. */
    public static function secret(string $title, string $body): void
    {
        self::send(Notification::success($body)->title($title)->persistent());
    }

    private static function send(Notification $n): void
    {
        NotificationManager::send($n);
    }
}
