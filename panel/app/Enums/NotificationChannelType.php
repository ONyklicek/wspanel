<?php

namespace App\Enums;

use NyonCode\WireCore\Foundation\Contracts\Enum\HasLabel;

enum NotificationChannelType: string implements HasLabel
{
    case Email = 'email';
    case Slack = 'slack';
    case Discord = 'discord';
    case Telegram = 'telegram';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Slack => 'Slack',
            self::Discord => 'Discord',
            self::Telegram => 'Telegram',
        };
    }

    /**
     * Webhook addresses are fixed to each service's own host: the panel never
     * sends a request to an address a user typed (no internal targets).
     */
    public function webhookPattern(): ?string
    {
        return match ($this) {
            self::Slack => '#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]{10,200}$#',
            self::Discord => '#^https://(discord|discordapp)\.com/api/webhooks/[0-9]{5,30}/[A-Za-z0-9_-]{20,200}$#',
            default => null,
        };
    }
}
