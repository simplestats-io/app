<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\StatsCustomEvent;
use App\Models\StatsCustomEventName;
use App\Models\StatsLogin;
use App\Models\StatsPayment;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use Carbon\CarbonInterface;
use Database\Seeders\Concerns\SeedsPageViews;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HistoricalStatsSeeder extends Seeder
{
    use SeedsPageViews;

    const DEFAULT_CURRENCY = 'EUR';

    /** Tiers ordered cheapest to most expensive so price moves map onto plan switches. */
    const SUBSCRIPTION_PLANS = ['Starter', 'Pro', 'Business', 'Enterprise'];

    /**
     * Seed historical stats data (users, visitors, logins, payments and
     * custom events) for every existing project.
     */
    public function run(): void
    {
        $timezone = auth()->user()->team->timezone;

        // One transaction per project so the thousands of individual inserts are
        // committed in a single batch instead of one fsync per row.
        Project::all()->each(fn ($project) => DB::transaction(function () use ($project, $timezone): void {
            $eventNames = collect(['signup_click', 'purchase_click', 'page_view', 'add_to_cart', 'checkout_start'])
                ->map(fn ($name) => StatsCustomEventName::firstOrCreate([
                    'team_id' => $project->team_id,
                    'project_id' => $project->id,
                    'name' => $name,
                ]));

            $tracksSubscription = (bool) ($project->usage & Project::USAGE_SUBSCRIPTION);

            $count = $this->getUserSeedCount();

            // Logins have no lookup columns and their counter is just the loop
            // index, so we collect plain rows and bulk insert them per project
            // instead of paying the full model/event cost for each of the many
            // thousands of logins (the dominant volume here).
            $loginRows = [];

            StatsUser::factory(random_int($count['from'], $count['to']))->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
            ])->each(function (StatsUser $statsUser) use ($project, $eventNames, $timezone, $tracksSubscription, &$loginRows): void {
                $visitorCount = app()->environment('testing') ? random_int(1, 2) : random_int(3, 6);
                $visitors = StatsVisitor::factory($visitorCount)->create([
                    'team_id' => $project->team_id,
                    'project_id' => $project->id,
                    'time' => $statsUser->time,
                ]);

                // Link ~70% of registrations to a prior visit so Time-to-Convert has data;
                // the converting visit happened up to two weeks before the signup.
                if (random_int(1, 100) <= 70) {
                    $convertedFrom = $visitors->first();
                    $convertedFrom->update([
                        'time' => Carbon::parse($statsUser->time)->subDays(random_int(0, 14)),
                    ]);
                    $statsUser->update(['stats_visitor_id' => $convertedFrom->id]);
                }

                $loginTime = Carbon::parse($statsUser->time);
                $loginCount = app()->environment('testing') ? random_int(0, 3) : random_int(0, 20);
                for ($i = 0; $i < $loginCount; $i++) {
                    $loginRows[] = [
                        'team_id' => $project->team_id,
                        'project_id' => $project->id,
                        'stats_user_id' => $statsUser->id,
                        // counter is the per-user sequence number (1st, 2nd, ... login)
                        'counter' => $i + 1,
                        ...$this->timeColumns($loginTime, $timezone),
                    ];
                    // increment a random number of days for the next login
                    $loginTime->addDays(random_int(1, 28));
                }

                if ($tracksSubscription && random_int(1, 100) <= 40) {
                    $this->seedSubscription($project, $statsUser);
                } elseif (random_int(0, 9) === 0) {
                    $paymentTime = Carbon::parse($statsUser->time);
                    // Keep one-time lifetime modest so recurring subscribers (whose LTV
                    // builds over many renewals) rank as the actual power users instead of
                    // a handful of users who happened to fire 20 large one-off payments.
                    $paymentCount = app()->environment('testing') ? random_int(0, 3) : random_int(1, 4);
                    for ($i = 0; $i < $paymentCount; $i++) {
                        StatsPayment::factory()->create([
                            'team_id' => $project->team_id,
                            'project_id' => $project->id,
                            'stats_user_id' => $statsUser->id,
                            'currency' => self::DEFAULT_CURRENCY,
                            // increment a random number of days for the next payment
                            'time' => $paymentTime->addDays(random_int(1, 28)),
                        ]);
                    }
                }

                // 40% of users fire 1-3 events
                if (random_int(0, 4) < 2) {
                    $visitor = $visitors->random();
                    for ($i = 0; $i < random_int(1, 3); $i++) {
                        StatsCustomEvent::factory()->create([
                            'project_id' => $project->id,
                            'team_id' => $project->team_id,
                            'stats_user_id' => $statsUser->id,
                            'stats_visitor_id' => $visitor->id,
                            'stats_custom_event_name_id' => $eventNames->random()->id,
                            'time' => Carbon::parse($statsUser->time)->addDays(random_int(0, 30)),
                        ]);
                    }
                }
            });

            collect($loginRows)->chunk(1000)->each(fn ($chunk) => StatsLogin::insert($chunk->all()));

            $this->seedPageViews($project);
        }));
    }

    /**
     * Seed a recurring subscription as a chain of renewal payments at the chosen
     * interval, starting from the user's first-seen time until now (some chains
     * stop early, which the MRR aggregation reads as churn). The price stays
     * stable across renewals apart from the occasional plan change, so the MRR
     * line is clean while still producing realistic expansion/contraction.
     */
    protected function seedSubscription(Project $project, StatsUser $statsUser): void
    {
        $yearly = random_int(1, 5) === 1;
        $now = now();
        $time = Carbon::parse($statsUser->time);

        $amount = $yearly ? random_int(19000, 240000) / 100 : random_int(1900, 19900) / 100;

        $planIndex = random_int(0, count(self::SUBSCRIPTION_PLANS) - 1);

        $maxRenewals = $yearly ? 4 : 36;
        $renewals = app()->environment('testing') ? random_int(1, 3) : random_int(1, $maxRenewals);

        for ($i = 0; $i < $renewals && $time->lessThanOrEqualTo($now); $i++) {
            if ($i > 0 && random_int(1, 100) <= 12) {
                $expansion = random_int(0, 1) === 1;
                $amount = round($amount * ($expansion ? random_int(110, 150) : random_int(60, 90)) / 100, 2);

                // A price change is a plan switch: nudge the tier in the same direction.
                $planIndex = max(0, min(count(self::SUBSCRIPTION_PLANS) - 1, $planIndex + ($expansion ? 1 : -1)));
            }

            $factory = $yearly
                ? StatsPayment::factory()->subscriptionYearly()
                : StatsPayment::factory()->subscriptionMonthly();

            $factory->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'stats_user_id' => $statsUser->id,
                'currency' => self::DEFAULT_CURRENCY,
                'gross' => $amount,
                'net' => round($amount * 0.8, 2),
                'subscription_plan' => self::SUBSCRIPTION_PLANS[$planIndex],
                'time' => $time->copy(),
            ]);

            // Jitter keeps renewals within the MRR grace window of the interval.
            $time = $yearly
                ? $time->copy()->addYear()->addDays(random_int(-2, 2))
                : $time->copy()->addMonth()->addDays(random_int(-2, 2));
        }
    }

    /**
     * Build the team-timezone-aware time columns the BaseStat time mutator would
     * otherwise produce, for use in bulk inserts that bypass the model.
     *
     * @return array{time: string, time_utc: string}
     */
    protected function timeColumns(CarbonInterface $time, string $timezone): array
    {
        return [
            'time' => $time->copy()->tz($timezone)->format('Y-m-d H:i:s'),
            'time_utc' => $time->copy()->tz('UTC')->format('Y-m-d H:i:s'),
        ];
    }

    protected function getUserSeedCount(): array
    {
        return [
            'from' => match (true) {
                app()->environment('demo') => 500,
                app()->environment('testing') => 2,
                default => 500,
            },
            'to' => match (true) {
                app()->environment('demo') => 1000,
                app()->environment('testing') => 3,
                default => 1000,
            },
        ];
    }
}
