<?php

use App\Models\Project;
use App\Models\StatsLocationCountry;
use App\Models\StatsLocationRegion;
use App\Models\StatsVisitor;
use Database\Seeders\TestUserSeeder;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->project = Project::first();
});

function visitorIn(Project $project, string $country, ?string $region = null): StatsVisitor
{
    return StatsVisitor::factory()->create([
        'team_id' => $project->team_id,
        'project_id' => $project->id,
        'location_country' => $country,
        'location_region' => $region,
    ]);
}

test('country spellings of the same code are merged into the canonical row', function (): void {
    $canonical = visitorIn($this->project, 'Netherlands (NL)');
    $spelled = visitorIn($this->project, 'The Netherlands (NL)');
    $nameless = visitorIn($this->project, ' (NL)');

    $this->artisan('app:unify-locations')->assertSuccessful();

    $countries = StatsLocationCountry::withoutGlobalScopes()->where('team_id', $this->project->team_id)->where('name', 'like', '%(NL)')->pluck('name', 'id');

    expect($countries->values()->all())->toBe(['Netherlands (NL)'])
        ->and($spelled->fresh()->stats_location_country_id)->toBe($canonical->stats_location_country_id)
        ->and($nameless->fresh()->stats_location_country_id)->toBe($canonical->stats_location_country_id);
});

test('a country without a canonical row yet is renamed in place', function (): void {
    $visitor = visitorIn($this->project, 'Turkey (TR)');

    $this->artisan('app:unify-locations')->assertSuccessful();

    expect(StatsLocationCountry::withoutGlobalScopes()->find($visitor->stats_location_country_id)->name)->toBe('Türkiye (TR)');
});

test('nameless locations are removed and their references cleared', function (): void {
    $visitor = visitorIn($this->project, ' ()', ' (NL)');

    $this->artisan('app:unify-locations')->assertSuccessful();

    expect($visitor->fresh()->stats_location_country_id)->toBeNull()
        ->and($visitor->fresh()->stats_location_region_id)->toBeNull()
        ->and(StatsLocationRegion::withoutGlobalScopes()->where('name', ' (NL)')->exists())->toBeFalse();
});
