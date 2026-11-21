<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Octane\FrankenPhp\ServerStateFile;
use Throwable;

/**
 * octane:reload on FrankenPHP re-applies the whole Caddy config, and requests arriving
 * meanwhile fail with a 500 or hang. FrankenPHP's own worker restart drains them instead.
 */
class ReloadOctaneWorkers extends Command
{
    protected $signature = 'app:reload-octane';

    protected $description = 'Restart the Octane FrankenPHP workers without dropping requests.';

    public function handle(): int
    {
        $state = (new ServerStateFile(config('octane.state_file')))->read();

        if (! isset($state['state']['adminPort'])) {
            $this->error('No running Octane server found.');

            return self::FAILURE;
        }

        // Workers boot from the path the server was started with. A server started from a
        // resolved release directory would boot the old release again after a deploy.
        $serverBasePath = Str::before($state['state']['octaneConfig']['state_file'] ?? '', '/storage/');

        if ($serverBasePath !== '' && ! is_link($serverBasePath) && realpath($serverBasePath) !== realpath(base_path())) {
            $this->error("Octane runs from {$serverBasePath}, not from this release. Start it with APP_BASE_PATH set to the current symlink.");

            return self::FAILURE;
        }

        $host = $state['state']['adminHost'] ?? 'localhost';

        try {
            Http::timeout(60)->post("http://{$host}:{$state['state']['adminPort']}/frankenphp/workers/restart")->throw();
        } catch (Throwable $e) {
            $this->error('Restarting the workers failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Octane workers restarted.');

        return self::SUCCESS;
    }
}
