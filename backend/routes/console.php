<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-failed --hours=48')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('deployments:reap-stale')->everyTenMinutes();
Schedule::command('backup-runs:reap-stale')->everyTenMinutes();
Schedule::command('certificates:renew')->dailyAt('03:30');
Schedule::command('servers:purge-trashed')->dailyAt('04:00');
// Sampling on the five minute mark is the same instant cron fires on, so any
// short task on a round schedule was caught by almost every sample while the
// quiet time between tasks was under-represented. The command waits a random
// part of a minute before probing, which decorrelates the two.
Schedule::command('servers:collect-metrics --jitter=45')->everyFiveMinutes()->withoutOverlapping();
