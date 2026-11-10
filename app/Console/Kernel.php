<?php

namespace App\Console;

use App\Console\Commands\ResetEventsCounter;
use App\Console\Commands\SendProjectReports;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;
use Spatie\Health\Models\HealthCheckResultHistoryItem;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        if (app()->environment('demo')) {
            $schedule->command('migrate:fresh --seed')
                ->monthly()->at('6:00')
                ->before(fn () => Artisan::call('down'))
                ->after(fn () => Artisan::call('up'))
                ->withoutOverlapping();
        }

        // It is generally a good idea to avoid the timeslot between 02:00 and 03:00 at night
        // in areas where daylight saving time changes occur, as this causes sometimes a
        // double backup or (worse) no backup at all.

        if (app()->isProduction()) {
            // MINUTELY
            $schedule->command(RunHealthChecksCommand::class)->everyMinute();
            $schedule->command(ScheduleCheckHeartbeatCommand::class)->everyMinute();

            // HOURLY
            $schedule->command(ResetEventsCounter::class)->hourly();
            $schedule->command(SendProjectReports::class)->hourly();

            // DAILY
            if (config('backup.enabled')) {
                $schedule->command('backup:clean')->daily()->at('01:00');
                $schedule->command('backup:run')->daily()->at('01:30');
            }

            $schedule->command('app:recalc-stats-starting-at')->daily()->at('01:45');
            $schedule->command('app:check-logins-and-payments-counter')->daily()->at('04:00');
            // the history model lives in the vendor package, so the default model:prune discovery never finds it
            $schedule->command('model:prune', ['--model' => [HealthCheckResultHistoryItem::class]])->daily()->at('04:15');

            // Self-hosted runs without plans, and its operators decide on their own when an account goes away.
            if (config('app.app_type') === 'saas') {
                $schedule->command('app:check-subscription-plan-alarm')->daily()->at('3:30');
                $schedule->command('app:check-inactive-users')->daily()->at('03:45');
                $schedule->command('app:purge-pending-deleted-users')->daily()->at('03:50');
                $schedule->command('app:send-installation-reminders')->hourly();
                $schedule->command('app:send-scheduled-announcements')->everyMinute()->withoutOverlapping();
            }

            // WEEKLY
            $schedule->command('location:update')->weekly()->at('05:00');
        }

        $schedule->command('app:clear-old-stats-aggregations')->weekly()->at('4:00');

        $schedule->command('horizon:snapshot')->everyFiveMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
