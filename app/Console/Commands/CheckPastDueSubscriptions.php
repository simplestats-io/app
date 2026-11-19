<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionPastDue;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Mail;

class CheckPastDueSubscriptions extends Command
{
    protected $signature = 'app:check-past-due-subscriptions';

    protected $description = 'Reminds users daily to update their payment method while their subscription is past due.';

    public function handle(): void
    {
        User::query()
            ->whereHas('subscriptions', function (Builder $query): void {
                $query->where('status', 'past_due');
            })
            ->each(function (User $user): void {
                Mail::to($user)->queue(new SubscriptionPastDue($user));
                $this->info('Past due mail queued for '.$user->email);
            });
    }
}
