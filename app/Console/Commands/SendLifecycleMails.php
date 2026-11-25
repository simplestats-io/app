<?php

namespace App\Console\Commands;

use App\Services\LifecycleMailService;
use Illuminate\Console\Command;

class SendLifecycleMails extends Command
{
    protected $signature = 'app:send-lifecycle-mails {--pretend : List the mails that are due without sending them}';

    protected $description = 'Sends the onboarding, feedback and re-engagement mails to team owners once they are due';

    public function handle(LifecycleMailService $lifecycleMails): void
    {
        if (! $this->option('pretend')) {
            $this->info("Sent {$lifecycleMails->send()} lifecycle mail(s).");

            return;
        }

        $rows = $lifecycleMails->due()
            ->map(fn (array $due): array => [$due['mail']::key(), $due['owner']->email, $due['team']->name])
            ->all();

        $this->table(['Mail', 'Owner', 'Team'], $rows);
        $this->info(count($rows).' lifecycle mail(s) due.');
    }
}
