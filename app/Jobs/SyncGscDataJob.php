<?php

namespace App\Jobs;

use App\Exceptions\GscReconnectRequiredException;
use App\Models\GscConnection;
use App\Services\Gsc\GscSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncGscDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public GscConnection $gscConnection,
        public ?string $startDate = null,
        public ?string $endDate = null,
    ) {
        // The default queue's retry_after (90s) is shorter than a large window takes.
        $this->onQueue('low');
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['gsc-sync', 'gsc-connection:'.$this->gscConnection->id];
    }

    public function handle(GscSyncService $sync): void
    {
        $this->gscConnection->refresh();

        if (! $this->gscConnection->isSyncable()) {
            return;
        }

        try {
            if ($this->startDate !== null && $this->endDate !== null) {
                $sync->syncWindow($this->gscConnection, CarbonImmutable::parse($this->startDate), CarbonImmutable::parse($this->endDate));

                return;
            }

            $sync->syncRecent($this->gscConnection);
        } catch (GscReconnectRequiredException) {
            // The connection is flagged, the project page asks the user to reconnect.
        }
    }
}
