<?php

namespace App\Console\Commands;

use App\Jobs\SyncGscDataJob;
use App\Models\GscConnection;
use Illuminate\Console\Command;

class SyncGscData extends Command
{
    protected $signature = 'app:sync-gsc-data';

    protected $description = 'Fetch the latest Google Search Console keywords of every connected project';

    public function handle(): int
    {
        $count = 0;

        GscConnection::withoutGlobalScopes()
            ->whereNotNull('site_url')
            ->whereNull('reconnect_required_at')
            ->lazyById()
            ->each(function (GscConnection $connection) use (&$count): void {
                dispatch(new SyncGscDataJob($connection));
                $count++;
            });

        $this->info("Dispatched {$count} Search Console sync job(s).");

        return self::SUCCESS;
    }
}
