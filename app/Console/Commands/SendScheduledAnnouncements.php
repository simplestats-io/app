<?php

namespace App\Console\Commands;

use App\Services\AnnouncementService;
use Illuminate\Console\Command;

class SendScheduledAnnouncements extends Command
{
    protected $signature = 'app:send-scheduled-announcements';

    protected $description = 'Send announcements whose scheduled time has been reached';

    public function handle(AnnouncementService $announcementService): void
    {
        $sent = $announcementService->sendDue();

        $this->info("Sent {$sent} scheduled announcement(s).");
    }
}
