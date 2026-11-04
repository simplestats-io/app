<?php

use App\Enums\StatsRangePreset;
use App\Models\Project;
use App\Models\StatsPayment;
use App\Models\StatsUser;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->currentTeam->update(['currency' => 'USD', 'timezone' => 'UTC']);
    Project::factory()->create(['team_id' => $this->user->currentTeam->id]);
    $this->actingAs($this->user);
});

test('dashboard route returns 200', function (): void {
    $this->get(route('dashboard'))->assertStatus(200);
});

test('dashboard renders Dashboard/Index with expected props', function (): void {
    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('teamStats')
            ->has('presets')
            ->has('allPresets')
            ->has('hiddenPresets')
            ->has('customPresets')
            ->has('filters')
            ->has('statsGroups')
            ->has('hasCustomEvents')
            ->has('statsGroupNames')
            ->has('topReferers')
            ->has('rangeTypes')
            ->has('rangeCurrent')
            ->has('freePlanWarning')
            ->has('subscriptionPlanAlarm')
            ->has('usageBits')
            ->has('comparisonTypes')
        );
});

test('all projects view merges tracking usage bits from individual projects', function (): void {
    // Remove default project created in beforeEach and create projects with specific usage bits
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS,
    ]);

    Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS,
    ]);

    $this->get(route('dashboard', ['project_id' => 0]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('teamStats.projects.0.usage', Project::USAGE_VISITORS)
        );
});

test('all projects view includes payment bits when one project has payments', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS,
    ]);

    Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS | Project::USAGE_PAYMENTS,
    ]);

    $expectedUsage = Project::USAGE_VISITORS | Project::USAGE_PAYMENTS;

    $this->get(route('dashboard', ['project_id' => 0]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('teamStats.projects.0.usage', $expectedUsage)
        );
});

test('dashboard exposes the mrr_series prop when the project has recurring payments', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ]);

    $statsUser = StatsUser::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
    ]);

    StatsPayment::factory()->subscriptionMonthly()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
        'stats_user_id' => $statsUser->id,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('mrr_series', fn ($series) => $series
                ->each(fn ($item) => $item
                    ->has('date')
                    ->has('date_clear')
                    ->has('mrr')
                    ->has('subscribers')
                )
            )
        );
});

test('dashboard exposes mrr_series_prev when a comparison is active', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ]);

    $statsUser = StatsUser::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
    ]);

    StatsPayment::factory()->subscriptionMonthly()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
        'stats_user_id' => $statsUser->id,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id, 'comparison' => 'period']))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('mrr_series_prev', fn ($series) => $series
                ->each(fn ($item) => $item
                    ->has('date')
                    ->has('date_clear')
                    ->has('mrr')
                    ->has('subscribers')
                )
            )
        );
});

test('dashboard mrr_series_prev is null without a comparison', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ]);

    $statsUser = StatsUser::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
    ]);

    StatsPayment::factory()->subscriptionMonthly()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
        'stats_user_id' => $statsUser->id,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('mrr_series_prev', null)
        );
});

test('dashboard mrr_series prop is null when the project does not track recurring revenue', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('mrr_series', null)
        );
});

test('dashboard exposes a zeroed mrr_series when the project tracks recurring but has no payment yet', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('mrr_series', fn ($series) => $series
                ->each(fn ($item) => $item
                    ->has('date')
                    ->has('date_clear')
                    ->where('mrr', 0)
                    ->where('subscribers', 0)
                )
            )
        );
});

test('dashboard mrr_series prop is null when the project tracks payments but not users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_PAYMENTS,
    ]);

    $statsUser = StatsUser::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
    ]);

    StatsPayment::factory()->subscriptionMonthly()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
        'stats_user_id' => $statsUser->id,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('mrr_series', null)
        );
});

test('dashboard mrr_series prop is null when the project has no payments tracking', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('mrr_series', null)
        );
});

test('retention route renders Dashboard/Index in retention mode with cohorts when the project tracks users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS,
    ]);

    $this->get(route('retention', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('showRetention', true)
            ->has('retention_cohorts.period_count')
            ->has('retention_cohorts.cohorts')
        );
});

test('dashboard route renders with retention off and no cohorts', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('showRetention', false)
            ->where('retention_cohorts', null)
        );
});

test('retention route exposes no cohorts when the project does not track users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS,
    ]);

    $this->get(route('retention', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('showRetention', true)
            ->where('retention_cohorts', null)
        );
});

test('dashboard persists preset selection in user settings', function (): void {
    $this->get(route('dashboard', ['preset' => StatsRangePreset::LAST_7_DAYS->value]))
        ->assertStatus(200);

    $this->user->refresh();

    $teamId = $this->user->current_team_id;
    expect($this->user->settings['statsFilters'][$teamId] ?? null)->not->toBeNull();
    expect($this->user->settings['statsFilters'][$teamId]['preset'] ?? null)
        ->toBe(StatsRangePreset::LAST_7_DAYS->value);
});

test('retention route exposes engagement_series when the project tracks users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS,
    ]);

    $this->get(route('retention', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('engagement_series')
        );
});

test('retention engagement_series is null when the project does not track users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS,
    ]);

    $this->get(route('retention', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('engagement_series', null)
        );
});

test('dashboard route does not build engagement_series', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('engagement_series', null)
        );
});

test('metric route exposes time_to_convert_series when the project tracks visitors and users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS,
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'pd_visitor', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->has('time_to_convert_series')
        );
});

test('metric route time_to_convert_series is null when the project does not track users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS,
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'pd_visitor', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('time_to_convert_series', null)
        );
});

test('dashboard route does not build time_to_convert_series', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS,
    ]);

    $this->get(route('dashboard', ['project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('time_to_convert_series', null)
        );
});

test('metric route renders Dashboard/Metric with the kpi prop', function (): void {
    $this->get(route('dashboard.metric', ['kpi' => 'pd_visitor']))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('kpi', 'pd_visitor')
            ->where('plan_breakdown', null)
        );
});

test('metric route exposes plan_breakdown for an MRR kpi on a subscription project', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ]);

    $statsUser = StatsUser::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
    ]);

    StatsPayment::factory()->subscriptionMonthly()->create([
        'team_id' => $this->user->currentTeam->id,
        'project_id' => $project->id,
        'stats_user_id' => $statsUser->id,
        'time' => now(),
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'mrr', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('kpi', 'mrr')
            ->has('plan_breakdown', 1)
        );
});

test('metric route returns null plan_breakdown for a non-MRR kpi', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'pd_reg', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('plan_breakdown', null)
        );
});

test('metric route exposes engagement_series for an engagement kpi drilled into from retention', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS,
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'stickiness', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('kpi', 'stickiness')
            ->has('engagement_series')
        );
});

test('metric route exposes engagement_series for any kpi when the project tracks users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS,
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'pd_visitor', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('kpi', 'pd_visitor')
            ->has('engagement_series')
        );
});

test('metric route does not build engagement_series when the project does not track users', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS,
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'pd_visitor', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('engagement_series', null)
        );
});

test('metric route exposes revenue_churn for a churn kpi drilled into from revenue', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'net_churn', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('kpi', 'net_churn')
            ->has('revenue_churn')
        );
});

test('metric route does not build revenue_churn for a non-churn kpi', function (): void {
    Project::where('team_id', $this->user->currentTeam->id)->delete();

    $project = Project::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ]);

    $this->get(route('dashboard.metric', ['kpi' => 'mrr', 'project_id' => $project->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Metric')
            ->where('revenue_churn', null)
        );
});

test('dashboard shows the installation steps with the token until the team receives data', function (): void {
    $project = $this->user->currentTeam->projects()->first();

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('awaitingFirstData.project.uuid', $project->uuid)
            ->where('awaitingFirstData.token', $project->tokens()->first()->token_plain_part)
        );
});

test('dashboard drops the installation steps once the team has received data', function (): void {
    $this->user->currentTeam->update(['events_count_overall' => 1]);

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('awaitingFirstData', null));
});

test('dashboard hides the api token from viewers while awaiting data', function (): void {
    $viewer = User::factory()->create();
    $this->user->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
    $viewer->switchTeam($this->user->currentTeam);

    $this->actingAs($viewer)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('awaitingFirstData.project')
            ->where('awaitingFirstData.token', null)
        );
});

test('stats status reports whether the team has received data', function (): void {
    $this->getJson(route('stats.status'))->assertExactJson(['hasData' => false]);

    $this->user->currentTeam->update(['events_count_overall' => 3]);

    $this->getJson(route('stats.status'))->assertExactJson(['hasData' => true]);
});
