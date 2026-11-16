<?php

namespace App\Builder;

use App\Enums\TrackablePerson;

/**
 * Groups stats by the values of a single custom property (e.g. "ab_test"
 * bucketed into A / B).
 *
 * Property lookups are project-scoped, so the same property may exist once per
 * project. The :propertyNameIds placeholder carries every name id that belongs
 * to the selected property (one id for a project view, all same-named ids for
 * the "All projects" view) and the buckets are grouped by the value STRING, so
 * e.g. "free" from two projects merges into a single row.
 *
 * The properties pivot is bound to either a StatsUser or a StatsVisitor, so
 * each metric joins the pivot to its entity. The pivot table is aliased
 * `stats` in every CTE so the shared :projectCondition placeholder applies.
 */
class StatsAggregationsGroupedPropertiesSqlBuilder extends BaseSqlBuilder
{
    public function __construct(
        protected string $statsSort,
        protected int $limit,
        protected bool $lifetime = false,
        protected bool $withViews = false,
    ) {}

    public function build(): string
    {
        $this->applySql($this->getCTE());
        $this->applySql($this->getVisitors());
        $this->applySql($this->getReg());
        $this->applySql($this->getNet(), $this->withViews);

        if ($this->withViews) {
            $this->applySql($this->getViews(), false);
        }

        $this->applySql('SELECT * FROM (', false);
        $this->applySql($this->getSELECT(), false);
        $this->applySql($this->getFROM(), false);
        $this->applySql(') grouped_rows', false);
        $this->applySql('WHERE reg <> 0 OR visitors <> 0 OR net <> 0', false);
        $this->applySql($this->getORDER(), false);
        $this->applySql("LIMIT $this->limit", false);

        return $this->sql;
    }

    /**
     * The buckets: every distinct value (by string) of the selected property.
     */
    protected function getCTE(): string
    {
        return 'WITH stats_groups AS (
            SELECT MIN(id) as type_id, value as name FROM stats_custom_property_values stats
            WHERE :teamCondition AND stats.stats_custom_property_name_id IN (:propertyNameIds)
            GROUP BY value
        )';
    }

    /**
     * Visitors bound to each property value. :visitorEntityConditions applies
     * the active filters (tracking, event, property value) to the sv entity,
     * so an active filter narrows the buckets like in the other groups.
     */
    protected function getVisitors(): string
    {
        return "lt_visitors AS (
            SELECT spv.value as value_name, COUNT(DISTINCT sv.id) as count
            FROM stats_custom_properties stats
            INNER JOIN stats_custom_property_values spv ON spv.id = stats.stats_custom_property_value_id
            INNER JOIN stats_visitors sv ON CAST(sv.id AS varchar) = stats.propertyable_id AND sv.project_id = stats.project_id
            WHERE :projectCondition AND stats.propertyable_type = 'stats_visitor' AND stats.stats_custom_property_name_id IN (:propertyNameIds)
                {$this->timeWindow('sv')} :visitorEntityConditions
            GROUP BY spv.value
        )";
    }

    /**
     * Registrations (users) bound to each property value. User ids are only
     * unique per project, so the distinct count includes the project id.
     */
    protected function getReg(): string
    {
        return 'lt_reg AS (
            SELECT spv.value as value_name, COUNT(DISTINCT '.$this->getDistinctFields(['su.id', 'su.project_id']).") as count
            FROM stats_custom_properties stats
            INNER JOIN stats_custom_property_values spv ON spv.id = stats.stats_custom_property_value_id
            INNER JOIN stats_users su ON su.id = stats.propertyable_id AND su.project_id = stats.project_id
            WHERE :projectCondition AND stats.propertyable_type = 'stats_user' AND stats.stats_custom_property_name_id IN (:propertyNameIds)
                {$this->timeWindow('su')} :userEntityConditions
            GROUP BY spv.value
        )";
    }

    /**
     * Net revenue from the users bound to each property value. The pivot holds
     * exactly one row per (user, property name), so payments cannot fan out.
     * The su join exists only to apply :userEntityConditions to the paying user.
     */
    protected function getNet(): string
    {
        return "lt_net AS (
            SELECT spv.value as value_name, SUM(pay.net) as count
            FROM stats_custom_properties stats
            INNER JOIN stats_custom_property_values spv ON spv.id = stats.stats_custom_property_value_id
            INNER JOIN stats_users su ON su.id = stats.propertyable_id AND su.project_id = stats.project_id
            INNER JOIN stats_payments pay ON pay.stats_user_id = su.id AND pay.project_id = su.project_id
            WHERE :projectCondition AND stats.propertyable_type = 'stats_user' AND stats.stats_custom_property_name_id IN (:propertyNameIds)
                {$this->timeWindow('pay')} :userEntityConditions
            GROUP BY spv.value
        )";
    }

    /**
     * Views of the visitors bound to each property value, every visitor counted once.
     */
    protected function getViews(): string
    {
        return "lt_views AS (
            SELECT pv.value_name, SUM(vv.views) as count
            FROM (
                SELECT DISTINCT spv.value as value_name, sv.id
                FROM stats_custom_properties stats
                INNER JOIN stats_custom_property_values spv ON spv.id = stats.stats_custom_property_value_id
                INNER JOIN stats_visitors sv ON CAST(sv.id AS varchar) = stats.propertyable_id AND sv.project_id = stats.project_id
                WHERE :projectCondition AND stats.propertyable_type = 'stats_visitor' AND stats.stats_custom_property_name_id IN (:propertyNameIds)
                    {$this->timeWindow('sv')} :visitorEntityConditions
            ) pv
            INNER JOIN stats_visitor_views vv ON vv.stats_visitor_id = pv.id
            GROUP BY pv.value_name
        )";
    }

    /**
     * The date-range restriction shared by every CTE. In "Lifetime" mode the
     * dimension is range-independent (current state, lifetime revenue), so the
     * time window is dropped while the entity/cross-filter conditions stay.
     */
    protected function timeWindow(string $alias): string
    {
        if ($this->lifetime) {
            return '';
        }

        return "AND {$alias}.time >= ':startDateStart' AND {$alias}.time <= ':endDateEnd'";
    }

    protected function getSELECT(): string
    {
        $sql = 'SELECT
            sg.type_id as type_id,
            sg.name as name,
            COALESCE(lt_visitors.count, 0) AS visitors,
            COALESCE(lt_reg.count, 0) as reg,
            ROUND(COALESCE((lt_reg.count * 100.0 / NULLIF(lt_visitors.count, 0)), 0), 2) AS cr,
            COALESCE(lt_net.count, 0) AS net';

        if ($this->withViews) {
            $sql .= ',
            COALESCE(lt_views.count, 0) AS views';
        }

        return $sql;
    }

    protected function getFROM(): string
    {
        $sql = 'FROM
            stats_groups sg
            LEFT JOIN lt_visitors ON sg.name = lt_visitors.value_name
            LEFT JOIN lt_reg ON sg.name = lt_reg.value_name
            LEFT JOIN lt_net ON sg.name = lt_net.value_name';

        if ($this->withViews) {
            $sql .= '
            LEFT JOIN lt_views ON sg.name = lt_views.value_name';
        }

        return $sql;
    }

    protected function getORDER(): string
    {
        $allowed = ['visitors', 'reg', 'cr', 'net', 'name', ...($this->withViews ? ['views'] : [])];
        $sort = in_array($this->statsSort, $allowed) ? $this->statsSort : 'reg';

        return $this->getGroupedOrderBy($sort, TrackablePerson::USER);
    }
}
