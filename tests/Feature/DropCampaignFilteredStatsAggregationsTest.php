<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('drops only the cached aggregations built with a campaign filter', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;
    $project = Project::factory()->create(['team_id' => $team->id]);

    foreach (['campaign' => '{"campaign":12}', 'campaign and source' => '{"campaign":12,"stats_track_source_id":3}', 'source' => '{"stats_track_source_id":3}', 'unfiltered' => '[]', 'none' => null] as $hash => $filter) {
        DB::table('stats_aggregations')->insert([
            'team_id' => $team->id,
            'project_id' => $project->id,
            'filter_hash' => substr(md5($hash), 0, 32),
            'filter' => $filter,
            'type' => 'days',
            'time' => '2026-06-01 00:00:00',
            'data' => json_encode(['pd_visitor' => 1]),
        ]);
    }

    (require database_path('migrations/2026_11_24_000002_drop_campaign_filtered_stats_aggregations.php'))->up();

    expect(DB::table('stats_aggregations')->pluck('filter')->all())
        ->toEqualCanonicalizing(['{"stats_track_source_id":3}', '[]', null]);
});
