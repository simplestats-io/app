<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\StatsCustomEvent;
use App\Models\StatsCustomEventName;
use App\Models\StatsLogin;
use App\Models\StatsPayment;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use Database\Seeders\Concerns\SeedsPageViews;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FutureStatsSeeder extends Seeder
{
    use SeedsPageViews;

    const DEFAULT_CURRENCY = 'EUR';

    /**
     * Add extra data for the next 30 days with a guaranteed increasing trend
     * to showcase ongoing activity on the dashboard.
     */
    public function run(): void
    {
        $projects = Project::all();

        // One transaction per project so the thousands of individual inserts are
        // committed in a single batch instead of one fsync per row.
        foreach ($projects as $project) {
            DB::transaction(function () use ($project): void {
                $this->seedProjectFutureData($project);
                $this->seedPageViews($project);
            });
        }
    }

    protected function seedProjectFutureData(Project $project): void
    {
        // Track previous day values to ensure guaranteed growth
        $lastDayRegs = 5;
        $lastDayVisitors = 15;
        $lastDayLogins = 8;
        $lastDayPayments = 3;

        // Seed data for each day for the next 30 days with guaranteed increasing trend
        for ($day = 0; $day < 30; $day++) {
            $dayStart = now()->addDays($day)->startOfDay();
            $dayEnd = now()->addDays($day)->endOfDay();

            // Each metric increases by 0-2 per day (guaranteed not to decrease)
            $dailyRegistrations = $lastDayRegs + random_int(0, 2);
            $extraVisitors = $lastDayVisitors + random_int(0, 3);
            $loginCount = $lastDayLogins + random_int(0, 2);
            $paymentCount = $lastDayPayments + random_int(0, 1);

            // Update for next iteration
            $lastDayRegs = $dailyRegistrations;
            $lastDayVisitors = $extraVisitors;
            $lastDayLogins = $loginCount;
            $lastDayPayments = $paymentCount;

            // Create registrations
            for ($i = 0; $i < $dailyRegistrations; $i++) {
                $registrationTime = Carbon::instance(fake()->dateTimeBetween($dayStart, $dayEnd));

                $statsUser = StatsUser::factory()->create([
                    'team_id' => $project->team_id,
                    'project_id' => $project->id,
                    'time' => $registrationTime,
                ]);

                // Create 2-4 visitors for each registration
                StatsVisitor::factory(random_int(2, 4))->create([
                    'team_id' => $project->team_id,
                    'project_id' => $project->id,
                    'time' => $registrationTime,
                ]);

                // Each new user has 1-2 logins
                for ($j = 0; $j < random_int(1, 2); $j++) {
                    StatsLogin::factory()->create([
                        'team_id' => $project->team_id,
                        'project_id' => $project->id,
                        'stats_user_id' => $statsUser->id,
                        'time' => Carbon::instance(fake()->dateTimeBetween($registrationTime, $dayEnd)),
                    ]);
                }

                // 20% chance of payment for new users
                if (random_int(0, 4) === 0) {
                    StatsPayment::factory()->create([
                        'team_id' => $project->team_id,
                        'project_id' => $project->id,
                        'stats_user_id' => $statsUser->id,
                        'currency' => self::DEFAULT_CURRENCY,
                        'time' => Carbon::instance(fake()->dateTimeBetween($registrationTime, $dayEnd)),
                    ]);
                }
            }

            // Extra visitors
            StatsVisitor::factory($extraVisitors)->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'time' => Carbon::instance(fake()->dateTimeBetween($dayStart, $dayEnd)),
            ]);

            // Logins from existing users
            $existingUsers = StatsUser::query()
                ->where('project_id', $project->id)
                ->where('time', '<', $dayStart)
                ->inRandomOrder()
                ->limit($loginCount)
                ->get();

            foreach ($existingUsers as $user) {
                StatsLogin::factory()->create([
                    'team_id' => $project->team_id,
                    'project_id' => $project->id,
                    'stats_user_id' => $user->id,
                    'time' => Carbon::instance(fake()->dateTimeBetween($dayStart, $dayEnd)),
                ]);
            }

            // Payments from existing users
            $payingUsers = StatsUser::query()
                ->where('project_id', $project->id)
                ->where('time', '<', $dayStart)
                ->inRandomOrder()
                ->limit($paymentCount)
                ->get();

            foreach ($payingUsers as $user) {
                StatsPayment::factory()->create([
                    'team_id' => $project->team_id,
                    'project_id' => $project->id,
                    'stats_user_id' => $user->id,
                    'currency' => self::DEFAULT_CURRENCY,
                    'time' => Carbon::instance(fake()->dateTimeBetween($dayStart, $dayEnd)),
                ]);
            }

            // Events from existing users
            $eventNames = StatsCustomEventName::where('project_id', $project->id)->get();
            if ($eventNames->isNotEmpty()) {
                $eventUsers = StatsUser::query()
                    ->where('project_id', $project->id)
                    ->inRandomOrder()
                    ->limit(random_int(3, 8))
                    ->get();

                foreach ($eventUsers as $user) {
                    $visitor = StatsVisitor::where('project_id', $project->id)->inRandomOrder()->first();
                    StatsCustomEvent::factory()->create([
                        'project_id' => $project->id,
                        'team_id' => $project->team_id,
                        'stats_user_id' => $user->id,
                        'stats_visitor_id' => $visitor?->id,
                        'stats_custom_event_name_id' => $eventNames->random()->id,
                        'time' => Carbon::instance(fake()->dateTimeBetween($dayStart, $dayEnd)),
                    ]);
                }
            }
        }
    }
}
