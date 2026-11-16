<?php

use App\Enums\DataImportType;
use App\Jobs\ProcessDataImportJob;
use App\Models\DataImport;
use App\Models\Project;
use App\Models\StatsPage;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use App\Models\User;
use App\Validators\BaseStatsValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->team->update(['timezone' => 'UTC']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'domain' => 'example.com']);
    $this->actingAs($this->user);

    Mail::fake();
});

function runImport(DataImportType $type, string $csv, object $test): DataImport
{
    $dataImport = DataImport::forceCreate([
        'type' => $type,
        'team_id' => $test->team->id,
        'project_id' => $test->project->id,
        'created_by' => $test->user->id,
        'rows_max' => max(1, substr_count(trim($csv), "\n")),
    ]);

    $path = 'imports/'.$type->value.'-'.$dataImport->id.'.csv';
    Storage::put($path, $csv);

    dispatch_sync(new ProcessDataImportJob($dataImport, $path));

    return $dataImport->refresh();
}

function viewTotals(Project $project): array
{
    return [
        'views' => (int) DB::table('stats_visitor_views')->where('project_id', $project->id)->sum('views'),
        'viewers' => DB::table('stats_visitor_views')->where('project_id', $project->id)->count(),
    ];
}

test('the user import resolves the documented tracking columns, also when some cells are empty', function (): void {
    runImport(DataImportType::USERS, "id,time,track_source,page_entry\n1,2026-10-01 10:00:00,newsletter,/blog\n2,2026-10-01 11:00:00,,\n", $this);

    $user = StatsUser::with(['statsTrackSource', 'statsPageEntry'])->whereKey(1)->sole();

    expect(StatsUser::count())->toBe(2)
        ->and($user->statsTrackSource->name)->toBe('newsletter')
        ->and($user->statsPageEntry->name)->toBe('/blog');
});

test('visitors are imported with tracking lookups and tagged with the import', function (): void {
    $dataImport = runImport(DataImportType::VISITORS, implode("\n", [
        'visitor_hash,time,track_referer,track_source,page_entry',
        'v1,2026-10-01 10:00:00,www.google.com,newsletter,/pricing',
        'v2,2026-10-01 11:00:00,,,/',
    ])."\n", $this);

    $visitor = StatsVisitor::where('visitor_hash', 'v1')->sole();

    expect(StatsVisitor::count())->toBe(2)
        ->and($visitor->data_import_id)->toBe($dataImport->id)
        ->and($visitor->stats_track_source_id)->not->toBeNull()
        ->and($visitor->stats_track_referer_id)->not->toBeNull()
        ->and($visitor->stats_page_entry_id)->not->toBeNull();
});

test('a visitor import keeps an already known visitor untouched', function (): void {
    runImport(DataImportType::VISITORS, "visitor_hash,time,page_entry\nv1,2026-10-01 10:00:00,/live\n", $this);
    $dataImport = runImport(DataImportType::VISITORS, "visitor_hash,time,page_entry\nv1,2026-10-02 10:00:00,/import\n", $this);

    expect(StatsVisitor::where('visitor_hash', 'v1')->sole()->data_import_id)->not->toBe($dataImport->id);
});

test('page views attach to known visitors and drop unknown ones without retrying', function (): void {
    runImport(DataImportType::VISITORS, "visitor_hash,time\nv1,2026-10-01 10:00:00\n", $this);

    runImport(DataImportType::PAGE_VIEWS, implode("\n", [
        'visitor_hash,page,views',
        'v1,/pricing?ref=x,3',
        'v1,/,1',
        'unknown,/,5',
    ])."\n", $this);

    expect(viewTotals($this->project))->toBe(['views' => 4, 'viewers' => 1])
        ->and(StatsPage::pluck('name')->sort()->values()->all())->toBe(['/', '/pricing'])
        ->and(DB::table('stats_views_pending')->count())->toBe(0)
        ->and($this->project->refresh()->page_views_since)->not->toBeNull();
});

test('rolling back a visitor import removes its page views too', function (): void {
    $visitorImport = runImport(DataImportType::VISITORS, "visitor_hash,time\nv1,2026-10-01 10:00:00\n", $this);
    $pageViewImport = runImport(DataImportType::PAGE_VIEWS, "visitor_hash,page,views\nv1,/,2\n", $this);

    $this->post(route('data-import.rollback', $pageViewImport))->assertForbidden();
    $this->post(route('data-import.rollback', $visitorImport))->assertRedirect();

    expect(StatsVisitor::count())->toBe(0)
        ->and(viewTotals($this->project))->toBe(['views' => 0, 'viewers' => 0]);
})->skip(fn (): bool => config('app.app_type') !== 'saas', 'rollback needs the SaaS plan options');

test('a fathom export keeps visitors, page views, entry pages and sources exact per day', function (): void {
    $csv = implode("\n", [
        'date,utm_source,utm_medium,utm_campaign,utm_term,utm_content,referrer_hostname,referrer_pathname,hostname,pathname,ai_source,pageviews,visits,uniques',
        '2026-10-15,,,,,,,,https://example.com,/,,10,4,4',
        '2026-10-15,laravelnews,link,laravelnews,,,laravel-news.com,/,https://example.com,/daily/post,,3,2,2',
        '2026-10-15,,,,,,www.google.com,/,https://example.com,/daily,,2,0,1',
        '2026-10-16,,,,,,,,https://example.com,/,,1,1,1',
    ])."\n";

    $dataImport = runImport(DataImportType::FATHOM, $csv, $this);

    $visitorsPerDay = StatsVisitor::query()
        ->selectRaw('time::date AS day, count(*) AS visitors')
        ->groupBy('day')->orderBy('day')
        ->pluck('visitors', 'day')->map(fn ($count): int => (int) $count)->all();

    $pageViews = DB::table('stats_visitor_pages')
        ->join('stats_pages', 'stats_pages.id', '=', 'stats_visitor_pages.stats_page_id')
        ->where('stats_visitor_pages.project_id', $this->project->id)
        ->groupBy('stats_pages.name')
        ->selectRaw('stats_pages.name, sum(views) AS views, count(*) AS viewers')
        ->get()->keyBy('name');

    expect($visitorsPerDay)->toBe(['2026-10-15' => 6, '2026-10-16' => 1])
        ->and(viewTotals($this->project))->toBe(['views' => 16, 'viewers' => 7])
        ->and((int) $pageViews['/daily']->views)->toBe(2)
        ->and((int) $pageViews['/daily']->viewers)->toBe(1)
        ->and((int) $pageViews['/daily/post']->viewers)->toBe(2)
        ->and(StatsVisitor::whereNotNull('stats_track_campaign_id')->count())->toBe(2)
        ->and(StatsVisitor::where('data_import_id', $dataImport->id)->count())->toBe(7)
        ->and($dataImport->rows_max)->toBe(7)
        ->and($dataImport->rows_imported)->toBe(7);
});

test('a fathom export with unknown columns is rejected before the import starts', function (): void {
    $dataImport = new DataImport;
    $dataImport->type = DataImportType::FATHOM;

    expect(fn () => resolve(BaseStatsValidator::class)->validateImportFields($dataImport, ['date', 'pathname', 'pageviews', 'revenue']))
        ->toThrow(ValidationException::class);
});

test('tool imports are offered as cards, not as raw import types', function (): void {
    $this->get(route('data-import.index'))
        ->assertInertia(fn ($page) => $page
            ->where('importTypes', fn ($types): bool => collect($types)->pluck('id')->doesntContain(DataImportType::FATHOM->value))
            ->where('toolImportTypes.0.id', DataImportType::FATHOM->value)
            ->where('toolImportTypes.0.domain', 'usefathom.com'));
});
