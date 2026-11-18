<?php

use App\Backup\BackupsHealthCheck;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Storage;
use Spatie\Health\Facades\Health;

/**
 * @param  list<string>  $disks
 * @return list<BackupsHealthCheck>
 */
function registeredBackupChecks(bool $backupsEnabled, array $disks = ['local', 's3']): array
{
    config(['backup.enabled' => $backupsEnabled, 'backup.backup.destination.disks' => $disks]);

    Health::clearChecks();

    (new AppServiceProvider(app()))->boot();

    return Health::registeredChecks()
        ->filter(fn ($check): bool => $check instanceof BackupsHealthCheck)
        ->values()
        ->all();
}

function localBackupCheckStatus(?int $backupAgeInHours): string
{
    $disk = Storage::fake('local');

    if ($backupAgeInHours !== null) {
        $filename = now()->subHours($backupAgeInHours)->format('Y-m-d-H-i-s').'.zip';
        $disk->put(config('backup.backup.name').'/'.$filename, 'zip');
    }

    return registeredBackupChecks(true, ['local'])[0]->run()->status->value;
}

test('every backup disk gets its own check when backups are enabled', function (): void {
    $names = array_map(fn (BackupsHealthCheck $check): string => $check->getName(), registeredBackupChecks(true));

    expect($names)->toBe(['Backups (local)', 'Backups (s3)']);
});

test('no backup check is registered when backups are disabled', function (): void {
    expect(registeredBackupChecks(false))->toBe([]);
});

test('the check passes while the newest backup is from last night', function (): void {
    expect(localBackupCheckStatus(25))->toBe('ok');
});

test('the check fails when the nightly backup did not run', function (?int $backupAgeInHours): void {
    expect(localBackupCheckStatus($backupAgeInHours))->toBe('failed');
})->with([
    'backup too old' => 27,
    'no backup at all' => null,
]);
