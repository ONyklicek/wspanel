<?php

use App\Actions\Firewall\ManageGeoblock;
use App\Support\PanelRelease;
use Illuminate\Support\Facades\Schedule;

// The DB mirrors of services and firewall rules are refreshed from the agent;
// the panel never assumes state it did not verify.
Schedule::command('panel:sync')->everyMinute()->withoutOverlapping();
Schedule::command('panel:metrics')->everyMinute()->withoutOverlapping();
// Notifications: new and resolved problems, finished deploys (only changes).
Schedule::command('panel:alerts')->everyMinute()->withoutOverlapping();
// Traffic stats from the edge's access logs (and pruning of old history).
Schedule::command('panel:traffic')->everyFiveMinutes()->withoutOverlapping();
// Blocking by country: the address ranges change, download them again weekly.
Schedule::call(fn () => app(ManageGeoblock::class)->refresh())->name('geoblock-refresh')->weekly()->withoutOverlapping();
// A newer WSPanel: asked once a day, at a time of this server's own so that
// not every installation asks GitHub in the same minute.
Schedule::call(fn () => PanelRelease::check())->name('release-check')
    ->dailyAt(sprintf('%02d:%02d', crc32((string) config('app.key')) % 24, intdiv(crc32((string) config('app.url')), 7) % 60))
    ->when(fn () => PanelRelease::checkable())->withoutOverlapping();
