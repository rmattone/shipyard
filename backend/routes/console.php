<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-failed --hours=48')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('deployments:reap-stale')->everyTenMinutes();
Schedule::command('backup-runs:reap-stale')->everyTenMinutes();
Schedule::command('certificates:renew')->dailyAt('03:30');
Schedule::command('servers:purge-trashed')->dailyAt('04:00');
Schedule::command('servers:collect-metrics')->everyFiveMinutes()->withoutOverlapping();
