<?php

namespace Database\Seeders;

use App\Helpers\AppHelper;
use App\Models\Project;
use App\Models\StatsCustomEvent;
use App\Models\StatsLogin;
use App\Models\StatsPayment;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use App\Models\Team;
use App\Models\User;
use App\Observers\StatsObserver;
use App\Services\StatsService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(TestUserSeeder::class);

        // The stats factories below rely on an authenticated user (HasTeamScope
        // and the BaseStat time mutator), so set the context explicitly instead
        // of depending on TestUserSeeder's internal state (which cleans itself
        // up when running inside tests).
        $user = User::where('email', config('app.test.user')['email'])->firstOrFail();
        auth()->setUser($user);
        AppHelper::setTeamContextFromAuth($user);

        // Memoise the track/location/device lookup ids while seeding so the
        // thousands of generated stats don't each re-query the same lookup rows.
        // Only StatsUser and StatsVisitor carry these tracking properties.
        StatsUser::$cacheTrackingLookups = true;
        StatsVisitor::$cacheTrackingLookups = true;

        if (app()->isLocal()) {
            $this->seedLocalDemoProject();
            $this->seedLocalLimitedTeamMember();
        }

        // Seed all stats with the observer disabled: its per-row work (events
        // counter increments, alert notifications and aggregation-cache
        // invalidation) is wasteful and slow during a bulk seed. The counters
        // are recomputed in one pass below instead.
        StatsObserver::$enabled = false;

        $this->call(HistoricalStatsSeeder::class);

        $this->call(DataImportSeeder::class);

        // Add extra data for the next 30 days to show activity
        if (! app()->environment('testing')) {
            $this->call(FutureStatsSeeder::class);
        }

        StatsObserver::$enabled = true;

        // Normally maintained by the nightly app:recalc-stats-starting-at; without it
        // the "All Time" preset cannot reach back to the seeded history.
        resolve(StatsService::class)->recalculateStatsStartingAt();

        // Custom properties for every project, assigned to the now-existing
        // stats users and visitors (runs last so it covers future data too).
        $this->call(StatsCustomPropertySeeder::class);

        // Marketing campaigns + ad spend need the utm dimensions the historical
        // stats just created.
        $this->call(CampaignSeeder::class);

        StatsUser::$cacheTrackingLookups = false;
        StatsUser::flushTrackingLookupCache();
        StatsVisitor::$cacheTrackingLookups = false;
        StatsVisitor::flushTrackingLookupCache();

        $this->recomputeEventCounters();
    }

    /**
     * Recompute the team/project event counters the StatsObserver normally
     * maintains incrementally. Monthly counts use the team's timezone for the
     * month boundaries, mirroring the events-counter init migration.
     */
    protected function recomputeEventCounters(): void
    {
        $statModels = [StatsVisitor::class, StatsUser::class, StatsLogin::class, StatsPayment::class, StatsCustomEvent::class];

        Team::with('projects')->get()->each(function (Team $team) use ($statModels): void {
            $now = now()->setTimezone($team->timezone ?? config('app.timezone'));
            $monthRange = [$now->startOfMonth(), $now->endOfMonth()];

            $teamMonthly = 0;
            $teamOverall = 0;

            foreach ($team->projects as $project) {
                $projectMonthly = 0;
                $projectOverall = 0;

                foreach ($statModels as $model) {
                    $base = $model::withoutGlobalScope('team')->where('project_id', $project->id);
                    $projectOverall += (clone $base)->count();
                    $projectMonthly += (clone $base)->whereBetween('time', $monthRange)->count();
                }

                $project->update(['events_count_monthly' => $projectMonthly]);

                $teamMonthly += $projectMonthly;
                $teamOverall += $projectOverall;
            }

            $team->update([
                'events_count_monthly' => $teamMonthly,
                'events_count_overall' => $teamOverall,
            ]);
        });
    }

    /**
     * Create the local "SimpleStats" demo project for the test user and link
     * the first app user to it as a stats user.
     */
    protected function seedLocalDemoProject(): void
    {
        $user = User::where('email', config('app.test.user')['email'])->firstOrFail();

        $usage = Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION;

        Project::factory()->count(1)->state(['name' => 'SimpleStats', 'usage' => $usage])
            ->for(Team::find($user->current_team_id))
            ->afterCreating(function ($project): void {
                StatsUser::factory()->create([
                    'id' => User::find(1)->id,
                    'project_id' => $project->id,
                ]);
            })->create();
    }

    protected function seedLocalLimitedTeamMember(): void
    {
        $team = User::where('email', config('app.test.user')['email'])->firstOrFail()->currentTeam;
        $project = Project::query()->whereBelongsTo($team)->where('name', 'My great App')->firstOrFail();

        $member = User::factory()->create([
            'name' => 'Client Viewer',
            'email' => 'client@simplestats.test',
            'current_team_id' => $team->id,
        ]);

        $team->users()->attach($member, [
            'role' => 'viewer',
            'project_ids' => [$project->id],
        ]);
    }
}
