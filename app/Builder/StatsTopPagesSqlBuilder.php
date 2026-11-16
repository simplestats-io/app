<?php

namespace App\Builder;

/**
 * Top pages over stats_visitor_pages. Kept out of StatsService::$statsTypes on purpose:
 * that list also drives the API, MCP and report breakdowns, which do not offer pages yet.
 *
 * The page rows are aliased vv so :viewsProjectCondition narrows them by their copied
 * team/project/time, while every dimension filter applies through the visitor (stats).
 */
class StatsTopPagesSqlBuilder extends BaseSqlBuilder
{
    public const array SORTABLE = ['views', 'visitors', 'name'];

    public function __construct(
        protected string $statsSort,
        protected int $limit,
    ) {}

    public function build(): string
    {
        $sort = in_array($this->statsSort, self::SORTABLE, true) ? $this->statsSort : 'views';
        $direction = $sort === 'name' ? 'ASC' : 'DESC';

        $this->applySql("SELECT sp.id AS type_id, sp.name AS name, SUM(vv.views) AS views, COUNT(*) AS visitors
            FROM stats_visitor_pages vv
            INNER JOIN stats_visitors stats ON stats.id = vv.stats_visitor_id
            INNER JOIN stats_pages sp ON sp.id = vv.stats_page_id
            WHERE :viewsProjectCondition AND vv.time >= ':startDateStart' AND vv.time <= ':endDateEnd'
                AND :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                :trackingCondition :eventConditionVisitor :propertyConditionVisitor :cohortConditionVisitor
            GROUP BY sp.id, sp.name
            ORDER BY $sort $direction, views DESC, visitors DESC
            LIMIT $this->limit", false);

        return $this->sql;
    }
}
