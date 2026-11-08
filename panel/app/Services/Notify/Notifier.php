<?php

namespace App\Services\Notify;

use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one message to the channels that want it: e-mail through the local
 * Postfix, Slack and Discord webhooks, a Telegram bot. A failing channel is
 * marked (last_error) and never stops the others.
 */
class Notifier
{
    /**
     * @param  string  $event  problems | resolved | deploys
     * @param  int|null  $clientId  the owner (null = the server: administrators only)
     */
    public function broadcast(string $event, ?int $clientId, string $title, string $body = ''): int
    {
        $sent = 0;
        // A suspended client's users are signed out of everything, notifications included.
        $users = User::query()->with('client')->get()->filter(fn (User $u) => $u->isAdmin()
            || ($clientId !== null && $u->client_id === $clientId && $u->client !== null && $u->client->suspended_at === null));
        foreach (NotificationChannel::withoutGlobalScopes()->whereIn('user_id', $users->pluck('id'))->where('enabled', true)->get() as $channel) {
            if ($channel->wants($event) && $this->send($channel, $title, $body)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function send(NotificationChannel $channel, string $title, string $body = ''): bool
    {
        $text = trim($title."\n".$body);
        $cfg = $channel->config;
        try {
            match ($channel->type) {
                NotificationChannelType::Email => Mail::raw($text."\n\n— WSPanel ".config('app.url'), fn ($m) => $m->to($cfg['address'])->subject('[WSPanel] '.$title)),
                NotificationChannelType::Slack => Http::timeout(10)->post($cfg['url'], ['text' => $text])->throw(),
                NotificationChannelType::Discord => Http::timeout(10)->post($cfg['url'], ['content' => mb_substr($text, 0, 1900)])->throw(),
                NotificationChannelType::Telegram => Http::timeout(10)->post('https://api.telegram.org/bot'.$cfg['token'].'/sendMessage', ['chat_id' => $cfg['chat_id'], 'text' => $text])->throw(),
            };
            $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->saveQuietly();

            return true;
        } catch (Throwable $e) {
            $channel->forceFill(['last_error' => mb_substr(str_replace((string) ($cfg['token'] ?? "\0"), '***', $e->getMessage()), 0, 500)])->saveQuietly();

            return false;
        }
    }
}
