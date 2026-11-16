<?php

namespace App\Builder;

class StatsAggregationsGroupedEventsSqlBuilder extends BaseSqlBuilder
{
    public function __construct(
        protected string $statsSort,
        protected int $limit,
        protected bool $withViews = false,
    ) {}

    public function build(): string
    {
        $this->applySql($this->getCTE());
        $this->applySql($this->getTotalVisitors());
        $this->applySql($this->getEventStats());
        $this->applySql($this->getNet(), $this->withViews);

        if ($this->withViews) {
            $this->applySql($this->getViews(), false);
        }

        $this->applySql('SELECT * FROM (', false);
        $this->applySql($this->getSELECT(), false);
        $this->applySql($this->getFROM(), false);
        $this->applySql(') grouped_rows', false);
        $this->applySql('WHERE visitors <> 0 OR triggered <> 0 OR net <> 0', false);
        $this->applySql($this->getORDER(), false);
        $this->applySql("LIMIT $this->limit", false);

        return $this->sql;
    }

    protected function getCTE(): string
    {
        return 'WITH stats_groups AS (
            SELECT id as type_id, name FROM stats_custom_event_names stats WHERE :projectCondition :eventConditionGroup
        )';
    }

    /**
     * Total unique visitors in the project for the time period.
     */
    protected function getTotalVisitors(): string
    {
        return "total_visitors AS (
            SELECT COUNT(*) as count
            FROM stats_visitors stats
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :trackingCondition :propertyConditionForVisitors
        )";
    }

    /**
     * Unique visitors/users who triggered each event.
     */
    protected function getEventStats(): string
    {
        return "event_stats AS (
            SELECT stats.stats_custom_event_name_id as type_id,
                COUNT(DISTINCT COALESCE(stats.stats_user_id, CAST(stats.stats_visitor_id AS varchar))) as triggered
            FROM stats_custom_events stats
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :eventConditionEventName :trackingConditionForEvents :propertyConditionForEvents
            GROUP BY type_id
        )";
    }

    /**
     * Net revenue from users/visitors who triggered each event.
     */
    protected function getNet(): string
    {
        return "event_net AS (
            SELECT type_id, SUM(count) as count
            FROM (
                SELECT ce.stats_custom_event_name_id as type_id, SUM(stats.net) as count
                FROM stats_payments stats
                INNER JOIN stats_custom_events ce ON ce.stats_user_id = stats.stats_user_id AND ce.project_id = stats.project_id
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :eventConditionEventNameCe :trackingConditionForEventsCe :propertyConditionForEventsCe
                GROUP BY type_id
                UNION ALL
                SELECT ce.stats_custom_event_name_id as type_id, SUM(stats.net) as count
                FROM stats_payments stats
                INNER JOIN stats_custom_events ce ON ce.stats_visitor_id = stats.stats_visitor_id AND ce.project_id = stats.project_id
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :eventConditionEventNameCe :trackingConditionForEventsCe :propertyConditionForEventsCe
                GROUP BY type_id
            ) as combined_results
            GROUP BY type_id
        )";
    }

    /**
     * Views of the visitor-days that triggered each event, every visitor-day counted once.
     */
    protected function getViews(): string
    {
        return "event_views AS (
            SELECT ce.type_id, SUM(vv.views) as count
            FROM (
                SELECT DISTINCT stats.stats_custom_event_name_id as type_id, stats.stats_visitor_id
                FROM stats_custom_events stats
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND stats.stats_visitor_id IS NOT NULL
                    :eventConditionEventName :trackingConditionForEvents :propertyConditionForEvents
            ) ce
            INNER JOIN stats_visitor_views vv ON vv.stats_visitor_id = ce.stats_visitor_id
            GROUP BY ce.type_id
        )";
    }

    protected function getSELECT(): string
    {
        $sql = 'SELECT
            sg.type_id as type_id,
            sg.name as name,
            COALESCE((SELECT count FROM total_visitors), 0) AS visitors,
            COALESCE(es.triggered, 0) AS triggered,
            ROUND(COALESCE((es.triggered * 100.0 / NULLIF((SELECT count FROM total_visitors), 0)), 0), 2) AS cr,
            COALESCE(event_net.count, 0) AS net';

        if ($this->withViews) {
            $sql .= ',
            COALESCE(event_views.count, 0) AS views';
        }

        return $sql;
    }

    protected function getFROM(): string
    {
        $sql = 'FROM
            stats_groups sg
            LEFT JOIN event_stats es ON sg.type_id = es.type_id
            LEFT JOIN event_net ON sg.type_id = event_net.type_id';

        if ($this->withViews) {
            $sql .= '
            LEFT JOIN event_views ON sg.type_id = event_views.type_id';
        }

        return $sql;
    }

    protected function getORDER(): string
    {
        $allowed = ['visitors', 'triggered', 'cr', 'net', 'name', ...($this->withViews ? ['views'] : [])];
        $sort = in_array($this->statsSort, $allowed) ? $this->statsSort : 'visitors';

        return $this->getEventsGroupedOrderBy($sort);
    }
}
