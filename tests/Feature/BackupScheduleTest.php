<?php

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// The schedule only runs backups in production, so these force that environment
// and read back the commands the kernel registered.
function scheduledCommandsMatching(bool $backupsEnabled, string $needle): array
{
    config(['app.env' => 'production', 'backup.enabled' => $backupsEnabled]);
    app()->instance('env', 'production');

    $schedule = new Schedule;
    $kernel = resolve(Kernel::class);

    (fn () => $this->schedule($schedule))->call($kernel);

    return array_values(array_filter(
        array_map(fn (Event $event): string => (string) $event->command, $schedule->events()),
        fn (string $command): bool => str_contains($command, $needle),
    ));
}

test('the scheduler runs the daily backup when backups are enabled', function (): void {
    expect(scheduledCommandsMatching(true, 'backup:run'))->toHaveCount(1)
        ->and(scheduledCommandsMatching(true, 'backup:clean'))->toHaveCount(1)
        ->and(scheduledCommandsMatching(true, 'backup:monitor'))->toHaveCount(1);
});

test('the scheduler skips backups when they are disabled', function (): void {
    expect(scheduledCommandsMatching(false, 'backup:'))->toBe([])
        // the rest of the production schedule is untouched
        ->and(scheduledCommandsMatching(false, 'app:recalc-stats-starting-at'))->toHaveCount(1);
});

test('backups are on unless BACKUP_ENABLED turns them off', function (): void {
    expect(config('backup.enabled'))->toBeTrue();
});
