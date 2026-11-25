<?php

namespace App\Notifications\Lifecycle;

use App\Models\StatsVisitor;
use App\Models\Team;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Messages\MailMessage;

class DataStoppedNotification extends LifecycleNotification
{
    public static function key(): string
    {
        return 'data_stopped';
    }

    /**
     * Every project tracks visitors, so the newest visitor tells when data last
     * arrived (index on team_id, time). `time` is in the team timezone, which the
     * day thresholds tolerate. Teams quiet for more than 60 days are left alone,
     * a "did something break?" mail makes no sense months later.
     */
    public static function audience(): Builder
    {
        return Team::query()
            ->where('events_count_overall', '>', 0)
            ->whereNotExists(static::visitsSince(14))
            ->whereExists(static::visitsSince(60));
    }

    public function isPersonal(): bool
    {
        return true;
    }

    /**
     * A team at its monthly event limit has its ingest rejected, so no data
     * arrives although nothing is broken on the customer's side.
     */
    public function shouldSend(): bool
    {
        return ! resolve(SubscriptionService::class)->hasReachedMonthlyEventLimit($this->team);
    }

    public function lastVisitAt(): ?CarbonImmutable
    {
        $time = StatsVisitor::withoutGlobalScopes()->whereBelongsTo($this->team)->max('time');

        return $time ? CarbonImmutable::parse($time) : null;
    }

    protected function compose(MailMessage $mail): MailMessage
    {
        $appName = config('app.name');
        $projects = static::namedProjects($this->team->projects()->getQuery())->orderBy('name')->pluck('name');
        $projectNames = $projects->isEmpty() ? 'your projects' : '**'.$projects->implode('**, **').'**';
        $since = $this->lastVisitAt()?->format('F j') ?? 'a while';

        return $mail
            ->subject("No new data from your app since {$since}")
            ->line("{$appName} has not received any new data from {$projectNames} since {$since}.")
            ->line('If you paused the tracking on purpose, all good, just ignore this mail. If not, the usual suspects are a stopped queue worker, a changed API token or a deploy without the client package.')
            ->action('Open your dashboard', route('dashboard'))
            ->line("Reply to this mail if you'd like a hand, I'm happy to take a look with you.");
    }

    /**
     * @return Builder<StatsVisitor>
     */
    protected static function visitsSince(int $days): Builder
    {
        return StatsVisitor::withoutGlobalScopes()
            ->whereColumn('stats_visitors.team_id', 'teams.id')
            ->where('time', '>=', now()->subDays($days)->format('Y-m-d H:i:s'));
    }
}
