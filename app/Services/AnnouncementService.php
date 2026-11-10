<?php

namespace App\Services;

use App\Jobs\SendAnnouncementToUser;
use App\Models\Announcement;
use App\Models\User;
use Spatie\DeletedModels\Models\DeletedModel;

class AnnouncementService
{
    /**
     * @param  array{admins_only?: bool, include_deleted_users?: bool, custom_emails?: array<string>}  $recipients
     * @return int number of queued mails
     */
    public function dispatch(string $subject, string $body, array $recipients, ?User $sender): int
    {
        $customEmails = collect($recipients['custom_emails'] ?? [])
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter()
            ->unique();

        if ($customEmails->isNotEmpty()) {
            foreach ($customEmails as $email) {
                dispatch(new SendAnnouncementToUser('', $email, $subject, $body));
            }

            return $customEmails->count();
        }

        $recipientCount = 0;
        $query = User::whereNotNull('email_verified_at');

        if ($recipients['admins_only'] ?? false) {
            $query->where('is_admin', true);
        } elseif (! app()->isProduction()) {
            $query->whereKey($sender?->getKey());
        }

        $users = $query->get();
        $activeEmails = $users->pluck('email')->map(fn (string $email): string => strtolower($email))->toArray();

        foreach ($users as $user) {
            dispatch(new SendAnnouncementToUser($user->name, $user->email, $subject, $body));
            $recipientCount++;
        }

        if ($recipients['include_deleted_users'] ?? false) {
            $deletedUsers = DeletedModel::query()
                ->where('model', (new User)->getMorphClass())
                ->get();

            foreach ($deletedUsers as $deletedUser) {
                $email = $deletedUser->values['email'] ?? null;
                $name = $deletedUser->values['name'] ?? '';

                if (! $email || in_array(strtolower($email), $activeEmails, true)) {
                    continue;
                }

                dispatch(new SendAnnouncementToUser($name, $email, $subject, $body));
                $recipientCount++;
            }
        }

        return $recipientCount;
    }

    /**
     * @return int number of announcements sent
     */
    public function sendDue(): int
    {
        $sent = 0;

        Announcement::query()
            ->with('scheduledBy')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->utc())
            ->each(function (Announcement $announcement) use (&$sent): void {
                // Claiming the row first means a second scheduler run can never send the same announcement twice.
                $claimed = Announcement::whereKey($announcement)
                    ->whereNotNull('scheduled_at')
                    ->update(['scheduled_at' => null, 'last_sent_at' => now()]);

                if ($claimed === 0) {
                    return;
                }

                $this->dispatch($announcement->subject, $announcement->body, $announcement->recipients ?? [], $announcement->scheduledBy);
                $sent++;
            });

        return $sent;
    }
}
