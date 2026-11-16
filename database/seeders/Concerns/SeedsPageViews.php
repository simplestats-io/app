<?php

namespace Database\Seeders\Concerns;

use App\Models\Project;
use App\Models\StatsPage;
use Illuminate\Support\Facades\DB;

/**
 * Page views for the seeded visitors, written in bulk past the ingest pipeline.
 * Pure SQL on purpose: a row per visitor and page through PHP would dominate the seed time.
 */
trait SeedsPageViews
{
    private const array SEED_PAGES = [
        '/', '/pricing', '/features', '/blog', '/docs', '/docs/installation', '/docs/configuration',
        '/about', '/contact', '/changelog', '/integrations', '/customers', '/login', '/register',
        '/blog/my-super-successful-article', '/blog/why-we-are-awesome', '/blog/we-love-good-analytics',
    ];

    protected function seedPageViews(Project $project): void
    {
        StatsPage::insertOrIgnore(
            array_map(fn (string $name): array => ['team_id' => $project->team_id, 'name' => $name], self::SEED_PAGES)
        );

        // Ordered by popularity, so the power() pick below gives a Zipf-like distribution.
        $idsByName = StatsPage::where('team_id', $project->team_id)->whereIn('name', self::SEED_PAGES)->pluck('id', 'name');
        $pageIds = collect(self::SEED_PAGES)->map(fn (string $name): int => (int) $idsByName[$name]);
        $pageArray = '{'.$pageIds->implode(',').'}';

        // About 85% of the visitors reported views (the rest predates the client feature
        // or opened no countable page); 1 + exponential gives roughly 35% bounces.
        DB::insert(
            'INSERT INTO stats_visitor_views (stats_visitor_id, team_id, project_id, time, last_viewed_at, views)
            SELECT sv.id, sv.team_id, sv.project_id, sv.time, sv.time, 1 + floor(-ln(1 - random()) * 2.2)::int
            FROM stats_visitors sv
            WHERE sv.project_id = ? AND random() < 0.85
            ON CONFLICT (stats_visitor_id) DO NOTHING',
            [$project->id],
        );

        DB::insert(
            'INSERT INTO stats_visitor_pages (stats_visitor_id, stats_page_id, team_id, project_id, time, views)
            SELECT vv.stats_visitor_id, picked.page_id, vv.team_id, vv.project_id, vv.time, COUNT(*)
            FROM stats_visitor_views vv
            CROSS JOIN LATERAL generate_series(1, vv.views) AS g(n)
            CROSS JOIN LATERAL (
                SELECT (?::bigint[])[1 + floor(? * power(random(), 3) + 0 * g.n)::int] AS page_id
            ) picked
            WHERE vv.project_id = ?
              AND NOT EXISTS (SELECT 1 FROM stats_visitor_pages vp WHERE vp.stats_visitor_id = vv.stats_visitor_id)
            GROUP BY vv.stats_visitor_id, picked.page_id, vv.team_id, vv.project_id, vv.time
            ON CONFLICT (stats_visitor_id, stats_page_id) DO NOTHING',
            [$pageArray, $pageIds->count(), $project->id],
        );

        DB::update(
            'UPDATE projects SET page_views_since = range.first_time, page_views_seen_at = range.last_time::date
            FROM (SELECT MIN(time) AS first_time, MAX(time) AS last_time FROM stats_visitor_views WHERE project_id = ?) range
            WHERE projects.id = ? AND range.first_time IS NOT NULL',
            [$project->id, $project->id],
        );
    }
}
