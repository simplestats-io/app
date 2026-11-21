<?php

namespace App\Console\Commands;

use App\Services\ReferrerSpamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UpdateReferrerSpamList extends Command
{
    protected $signature = 'app:update-referrer-spam-list';

    protected $description = 'Download the latest Matomo referrer spam list used to drop spam visitors at ingest.';

    public function handle(): int
    {
        try {
            $response = Http::timeout(30)->retry(2, 1000)->get(ReferrerSpamService::SOURCE_URL);
        } catch (Throwable $e) {
            $this->error('Download failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $domains = ReferrerSpamService::parseDomains($response->body());

        if (count($domains) < ReferrerSpamService::MIN_DOMAINS) {
            $this->error('Download looks incomplete ('.count($domains).' domains), keeping the current list.');

            return self::FAILURE;
        }

        // Write next to the target and rename, so ingest requests never read a
        // truncated file while it is being replaced.
        $disk = Storage::disk('local');
        $temporary = ReferrerSpamService::STORAGE_PATH.'.tmp';

        if (! $disk->put($temporary, implode("\n", $domains)."\n")
            || ! rename($disk->path($temporary), $disk->path(ReferrerSpamService::STORAGE_PATH))) {
            $this->error('Could not write the referrer spam list.');

            return self::FAILURE;
        }

        $this->info('Referrer spam list updated ('.count($domains).' domains).');

        return self::SUCCESS;
    }
}
