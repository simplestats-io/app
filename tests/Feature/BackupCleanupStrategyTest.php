<?php

use Illuminate\Support\Facades\Storage;

test('the server keeps only the newest backup while the bucket keeps the rotation', function (): void {
    $this->freezeTime();
    config(['backup.backup.destination.disks' => ['local', 's3']]);
    Storage::fake('local');
    Storage::fake('s3');

    $backupName = config('backup.backup.name');

    foreach (range(0, 19) as $daysAgo) {
        $fileName = $backupName.'/'.now()->subDays($daysAgo)->format('Y-m-d-H-i-s').'.zip';

        Storage::disk('local')->put($fileName, 'backup');
        Storage::disk('s3')->put($fileName, 'backup');
    }

    $this->artisan('backup:clean')->assertSuccessful();

    expect(Storage::disk('local')->allFiles($backupName))
        ->toBe([$backupName.'/'.now()->format('Y-m-d-H-i-s').'.zip'])
        ->and(Storage::disk('s3')->allFiles($backupName))->toHaveCount(20);
});
