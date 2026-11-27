<?php

namespace App\Builder;

/**
 * Search Console keywords. Like Top Pages kept out of StatsService::$statsTypes, and
 * visitor dimension filters do not apply: GSC rows are aggregates without visitors.
 * Only an entry page filter narrows them, matched by the page path.
 *
 * Estimated revenue per keyword: the page revenue of each landing page (payments of the
 * users/visitors who entered there) times the keyword's share of that page's traffic,
 * clicks(keyword, page) / GREATEST(clicks(all keywords, page), visitors(page)). Using the
 * larger denominator keeps Google from being credited with traffic it did not bring.
 */
class StatsKeywordsSqlBuilder extends BaseSqlBuilder
{
    public const array SORTABLE = ['clicks', 'impressions', 'ctr', 'position', 'est_net', 'name'];

    public function __construct(
        protected string $statsSort,
        protected int $limit,
        protected int $pageEntryId = 0,
    ) {}

    public function build(): string
    {
        // "Direct / Unknown" (-1) are visitors without an entry page, no keyword leads there.
        $pageFilter = match (true) {
            $this->pageEntryId > 0 => "AND sp.name = (SELECT name FROM stats_page_entries WHERE id = {$this->pageEntryId})",
            $this->pageEntryId < 0 => 'AND FALSE',
            default => '',
        };

        $window = "stats.date >= CAST(':startDateStart' AS date) AND stats.date <= CAST(':endDateEnd' AS date)";

        $this->applySql("WITH keyword_pages AS (
                SELECT stats.project_id, stats.stats_track_keyword_id AS type_id, sp.name AS page,
                    SUM(stats.clicks) AS clicks,
                    SUM(stats.impressions) AS impressions,
                    SUM(stats.impressions * stats.position) AS position_sum
                FROM stats_keyword_daily stats
                INNER JOIN stats_pages sp ON sp.id = stats.stats_page_id
                WHERE :projectCondition AND $window $pageFilter
                GROUP BY stats.project_id, stats.stats_track_keyword_id, sp.name
            ),
            page_clicks AS (
                SELECT stats.project_id, sp.name AS page, SUM(stats.clicks) AS clicks
                FROM stats_keyword_daily stats
                INNER JOIN stats_pages sp ON sp.id = stats.stats_page_id
                WHERE :projectCondition AND $window $pageFilter
                GROUP BY stats.project_id, sp.name
            ),
            page_visitors AS (
                SELECT stats.project_id, spe.name AS page, COUNT(*) AS visitors
                FROM stats_visitors stats
                INNER JOIN stats_page_entries spe ON spe.id = stats.stats_page_entry_id
                INNER JOIN page_clicks pc ON pc.project_id = stats.project_id AND pc.page = spe.name
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                GROUP BY stats.project_id, spe.name
            ),
            page_revenue AS (
                SELECT project_id, page, SUM(net) AS net
                FROM (
                    SELECT stats.project_id, spe.name AS page, SUM(stats.net) AS net
                    FROM stats_payments stats
                    INNER JOIN stats_users su ON su.id = stats.stats_user_id
                    INNER JOIN stats_page_entries spe ON spe.id = su.stats_page_entry_id
                    INNER JOIN page_clicks pc ON pc.project_id = stats.project_id AND pc.page = spe.name
                    WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                    GROUP BY stats.project_id, spe.name
                    UNION ALL
                    SELECT stats.project_id, spe.name AS page, SUM(stats.net) AS net
                    FROM stats_payments stats
                    INNER JOIN stats_visitors sv ON sv.id = stats.stats_visitor_id
                    INNER JOIN stats_page_entries spe ON spe.id = sv.stats_page_entry_id
                    INNER JOIN page_clicks pc ON pc.project_id = stats.project_id AND pc.page = spe.name
                    WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                        AND stats.stats_user_id IS NULL
                    GROUP BY stats.project_id, spe.name
                ) AS combined
                GROUP BY project_id, page
            ),
            keywords AS (
                SELECT kp.type_id,
                    SUM(kp.clicks) AS clicks,
                    SUM(kp.impressions) AS impressions,
                    SUM(kp.position_sum) AS position_sum,
                    SUM(kp.clicks * 1.0 / NULLIF(GREATEST(pc.clicks, COALESCE(pv.visitors, 0)), 0) * COALESCE(pr.net, 0)) AS est_net
                FROM keyword_pages kp
                INNER JOIN page_clicks pc ON pc.project_id = kp.project_id AND pc.page = kp.page
                LEFT JOIN page_visitors pv ON pv.project_id = kp.project_id AND pv.page = kp.page
                LEFT JOIN page_revenue pr ON pr.project_id = kp.project_id AND pr.page = kp.page
                GROUP BY kp.type_id
            )", false);

        $this->applySql("SELECT k.type_id, stk.name, k.clicks, k.impressions,
                ROUND(COALESCE(k.clicks * 100.0 / NULLIF(k.impressions, 0), 0)::numeric, 2) AS ctr,
                ROUND(COALESCE(k.position_sum / NULLIF(k.impressions, 0), 0)::numeric, 1) AS position,
                ROUND(COALESCE(k.est_net, 0)::numeric, 2) AS est_net
            FROM keywords k
            INNER JOIN stats_track_keywords stk ON stk.id = k.type_id
            WHERE k.impressions > 0
            {$this->orderBy()}
            LIMIT {$this->limit}", false);

        return $this->sql;
    }

    private function orderBy(): string
    {
        $sort = in_array($this->statsSort, self::SORTABLE, true) ? $this->statsSort : 'clicks';

        return match ($sort) {
            'name' => 'ORDER BY stk.name ASC',
            // Best position first, but a keyword with a single impression at #1 must not lead.
            'position' => 'ORDER BY (k.clicks = 0), position ASC, k.clicks DESC',
            default => "ORDER BY $sort DESC, k.clicks DESC, k.impressions DESC, stk.name ASC",
        };
    }
}
