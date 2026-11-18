<?php

namespace App\Backup;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Support\BackupFile;

/**
 * Health checks are registered in AppServiceProvider::boot(), i.e. on every
 * request. The vendor check resolves its disk right away in onDisk(), which
 * would build an S3 client for every ingest request. This one resolves the
 * disk only when the check actually runs.
 */
class BackupsHealthCheck extends BackupsCheck
{
    protected ?string $diskName = null;

    public function onDisk(string $disk): static
    {
        $this->diskName = $disk;

        return $this;
    }

    /**
     * @return Collection<int, BackupFile>
     */
    protected function getBackupFiles(): Collection
    {
        if ($this->diskName !== null) {
            $this->disk ??= Storage::disk($this->diskName);
        }

        return parent::getBackupFiles();
    }
}
