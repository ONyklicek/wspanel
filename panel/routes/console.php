<?php

use App\Actions\Firewall\ManageGeoblock;
use App\Services\Notify\Heartbeat;
use App\Support\AgentTasks;
use App\Support\Features;
use App\Support\PanelRelease;
use Illuminate\Support\Facades\Schedule;

// The DB mirrors of services and firewall rules are refreshed from the agent;
// the panel never assumes state it did not verify.
Schedule::command('panel:sync')->everyMinute()->withoutOverlapping();
Schedule::command('panel:metrics')->everyMinute()->withoutOverlapping();
// Long agent jobs end even with nobody on the page: their handlers run here.
Schedule::call(fn () => AgentTasks::settleAll())->name('agent-tasks')->everyMinute()->withoutOverlapping();
// Notifications: new and resolved problems, finished deploys (only changes).
Schedule::command('panel:alerts')->everyMinute()->withoutOverlapping();
// Watching from outside (Healthchecks.io), when set: Notifikace → Hlídání zvenku.
Schedule::command('panel:heartbeat')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => Heartbeat::url() !== null);
// Traffic stats from the edge's access logs (and pruning of old history).
Schedule::command('panel:traffic')->everyFiveMinutes()->withoutOverlapping();
// The server's own backup (state, configuration, mail) for wspanel-restore,
// nightly at a time of this server's own (between 01:00 and 04:59).
Schedule::command('panel:server-backup')
    ->dailyAt(sprintf('%02d:%02d', 1 + crc32((string) config('app.key')) % 4, crc32((string) config('app.url')) % 60))
    ->withoutOverlapping();
// Game servers' data, nightly (between 04:00 and 04:59), off-site too when set up.
Schedule::command('panel:game-backups')
    ->dailyAt(sprintf('04:%02d', crc32((string) config('app.key')) % 60))
    ->when(fn () => Features::games())->withoutOverlapping();
// DMARC aggregate reports for the hosted mail domains (from Postfix's spool).
Schedule::command('panel:dmarc')->everyFiveMinutes()->withoutOverlapping();
// Blocking by country: the address ranges change, download them again weekly.
Schedule::call(fn () => app(ManageGeoblock::class)->refresh())->name('geoblock-refresh')->weekly()->withoutOverlapping();
// A newer WSPanel: asked once a day, at a time of this server's own so that
// not every installation asks GitHub in the same minute.
Schedule::call(fn () => PanelRelease::check())->name('release-check')
    ->dailyAt(sprintf('%02d:%02d', crc32((string) config('app.key')) % 24, intdiv(crc32((string) config('app.url')), 7) % 60))
    ->when(fn () => PanelRelease::checkable())->withoutOverlapping();
