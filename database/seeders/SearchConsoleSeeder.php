<?php

namespace Database\Seeders;

use App\Models\GscConnection;
use App\Models\Project;
use App\Models\StatsKeywordDaily;
use App\Models\StatsPage;
use App\Models\StatsPageEntry;
use App\Models\StatsTrackKeyword;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Demo Search Console data: a linked property per project and daily keyword rows
 * on the project's real entry pages, so the dashboard's Keywords tab and its
 * estimated revenue have something to show. The tokens are fake, nothing syncs.
 */
class SearchConsoleSeeder extends Seeder
{
    private const int DAYS_BACK = 120;

    /**
     * keyword => [daily impressions, ctr, average position]
     */
    private const array KEYWORDS = [
        'laravel analytics' => [180, 0.09, 3.2],
        'server side analytics' => [120, 0.07, 4.8],
        'laravel tracking package' => [60, 0.14, 2.1],
        'google analytics alternative' => [400, 0.012, 11.5],
        'gdpr compliant analytics' => [150, 0.03, 8.4],
        'track user registrations laravel' => [35, 0.18, 1.7],
        'saas revenue analytics' => [90, 0.025, 9.9],
        'utm tracking laravel' => [40, 0.11, 3.9],
        'adblock proof analytics' => [55, 0.06, 6.2],
        'mrr dashboard' => [70, 0.02, 14.3],
        'cookieless analytics' => [110, 0.035, 7.7],
        'simple stats' => [25, 0.32, 1.2],
    ];

    public function run(): void
    {
        mt_srand(4711);

        Project::all()->each(function (Project $project): void {
            GscConnection::updateOrCreate(['project_id' => $project->id], [
                'team_id' => $project->team_id,
                'google_email' => 'webmaster@'.($project->domain ?: 'example.com'),
                'access_token' => Str::random(40),
                'site_url' => 'sc-domain:'.($project->domain ?: 'example.com'),
                'last_synced_at' => now(),
                'last_synced_until_date' => now()->subDays(2)->toDateString(),
            ]);

            $pages = StatsPageEntry::where('team_id', $project->team_id)->limit(6)->pluck('name')->whenEmpty(fn (): Collection => collect(['/']));
            $pageIds = $pages->mapWithKeys(fn (string $name): array => [
                $name => StatsPage::firstOrCreate(['team_id' => $project->team_id, 'name' => $name])->id,
            ]);
            $keywordIds = collect(self::KEYWORDS)->keys()->mapWithKeys(fn (string $name): array => [
                $name => StatsTrackKeyword::firstOrCreate(['team_id' => $project->team_id, 'name' => $name])->id,
            ]);

            StatsKeywordDaily::where('project_id', $project->id)->delete();

            $rows = [];
            $end = CarbonImmutable::now()->subDays(2);

            foreach (self::KEYWORDS as $keyword => [$impressions, $ctr, $position]) {
                $keywordPages = $pages->shuffle()->take(mt_rand(1, 2));

                for ($day = $end->subDays(self::DAYS_BACK); $day->lte($end); $day = $day->addDay()) {
                    foreach ($keywordPages as $page) {
                        $dailyImpressions = (int) round($impressions / $keywordPages->count() * mt_rand(60, 140) / 100);
                        $rows[] = [
                            'team_id' => $project->team_id,
                            'project_id' => $project->id,
                            'date' => $day->toDateString(),
                            'stats_track_keyword_id' => $keywordIds[$keyword],
                            'stats_page_id' => $pageIds[$page],
                            'clicks' => (int) round($dailyImpressions * $ctr * mt_rand(50, 150) / 100),
                            'impressions' => $dailyImpressions,
                            'position' => round(max(1, $position + mt_rand(-15, 15) / 10), 2),
                        ];
                    }
                }
            }

            foreach (array_chunk($rows, 1000) as $chunk) {
                StatsKeywordDaily::insert($chunk);
            }

        });
    }
}
