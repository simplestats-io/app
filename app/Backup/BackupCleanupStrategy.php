<?php

namespace App\Backup;

use Override;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;

/**
 * The copy on the server only serves quick restores, the rotation happens in the bucket.
 */
class BackupCleanupStrategy extends DefaultStrategy
{
    #[Override]
    public function deleteOldBackups(BackupCollection $backups): void
    {
        if ($this->backupDestination()->diskName() === 'local') {
            $backups->slice(1)->each(fn (Backup $backup) => $backup->delete());

            return;
        }

        parent::deleteOldBackups($backups);
    }
}
