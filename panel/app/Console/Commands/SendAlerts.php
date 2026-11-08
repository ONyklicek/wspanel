<?php

namespace App\Console\Commands;

use App\Models\AlertState;
use App\Models\Deployment;
use App\Models\Setting;
use App\Services\Notify\Alerts;
use App\Services\Notify\Notifier;
use App\Support\CurrentClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Announces changes, never states: a problem once it has been seen twice in
 * a row (a single failed check is not worth a message), its resolution, and
 * deploys that finished since the last run.
 */
#[Signature('panel:alerts')]
#[Description('Send notifications about new and resolved problems and finished deploys')]
class SendAlerts extends Command
{
    /** Consecutive checks a problem must be there before it is announced. */
    public const CONFIRMATIONS = 2;

    public function handle(Alerts $alerts, Notifier $notifier): int
    {
        $current = collect($alerts->current())->keyBy('key');

        foreach (AlertState::query()->get() as $state) {
            if (! $current->has($state->key)) {
                if ($state->notified_at !== null) {
                    $notifier->broadcast('resolved', $state->client_id, 'Vyřešeno: '.$state->title);
                }
                $state->delete();
            }
        }
        foreach ($current as $key => $a) {
            $state = AlertState::query()->firstOrNew(['key' => $key]);
            $state->fill(['client_id' => $a['client_id'], 'level' => $a['level'], 'title' => $a['title'], 'detail' => $a['detail']]);
            $state->seen = $state->exists ? $state->seen + 1 : 1;
            if ($state->notified_at === null && $state->seen >= self::CONFIRMATIONS) {
                $notifier->broadcast('problems', $a['client_id'], ($a['level'] === 'danger' ? 'Problém: ' : 'Upozornění: ').$a['title'], $a['detail']);
                $state->notified_at = now();
            }
            $state->save();
        }

        $this->deploys($notifier);

        return self::SUCCESS;
    }

    private function deploys(Notifier $notifier): void
    {
        $since = Setting::get('alerts.deploys_since');
        $finished = CurrentClient::unrestricted(fn () => Deployment::query()->with('site')->whereIn('status', ['success', 'failed', 'rolled_back'])
            ->whereNotNull('finished_at')->when($since, fn ($q) => $q->where('finished_at', '>', $since))->orderBy('finished_at')->get());
        if ($since !== null) {
            foreach ($finished as $d) {
                $site = $d->site;
                $ok = $d->status === 'success';
                $notifier->broadcast('deploys', $site?->client_id, ($ok ? 'Nasazeno: ' : 'Deploy selhal: ').($site?->domain ?? '?'),
                    trim(($d->ref ? $d->ref.' ' : '').($d->commit ? substr($d->commit, 0, 8) : '').($ok ? '' : "\n".$d->statusLabel().($d->error ? ': '.$d->error : ''))));
            }
        }
        Setting::put('alerts.deploys_since', ($finished->last()?->finished_at ?? now())->toIso8601String());
    }
}
