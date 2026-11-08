<?php

use Illuminate\Support\Facades\Schedule;

// The DB mirrors of services and firewall rules are refreshed from the agent;
// the panel never assumes state it did not verify.
Schedule::command('panel:sync')->everyMinute()->withoutOverlapping();
Schedule::command('panel:metrics')->everyMinute()->withoutOverlapping();
// Notifications: new and resolved problems, finished deploys (only changes).
Schedule::command('panel:alerts')->everyMinute()->withoutOverlapping();
