<?php

namespace App\Builder;

use App\Enums\TrackablePerson;

class StatsAggregationsGroupedSqlBuilder extends BaseSqlBuilder
{
    public function __construct(
        protected string $statsTable,
        protected string $statsDBType,
        protected string $statsSort,
        protected string $typeIdFallback,
        protected TrackablePerson $trackablePerson,
        protected int $limit,
        protected bool $withMrr = false,
        protected bool $withTimeToConvert = false,
        protected bool $withNau = false,
        /** @var array{int, int, int}|array{} day offsets per checkpoint, empty = retention columns off */
        protected array $retentionCheckpoints = [],
        protected bool $withViews = false,
    ) {}

    public function build(): string
    {
        $this->applySql($this->getCTE());

        if ($this->trackablePerson === TrackablePerson::USER) {
            $this->applySql($this->getPD_DAU());
            $this->applySql($this->getLT_REG());
        }

        $this->applySql($this->getLT_VISITORS());

        $this->applySql($this->getLT_NET(), $this->withMrr || $this->withTimeToConvert || $this->withNau || $this->hasRetention());

        if ($this->withMrr) {
            $this->applySql($this->getLT_MRR(), $this->withTimeToConvert || $this->withNau || $this->hasRetention());
        }

        if ($this->withTimeToConvert) {
            $this->applySql($this->getLT_TIME_TO_CONVERT(), $this->withNau || $this->hasRetention());
        }

        if ($this->withNau) {
            $this->applySql($this->getLT_NAU(), $this->hasRetention());
        }

        if ($this->hasRetention()) {
            $this->applySql($this->getLT_RETENTION(), false);
        }

        $this->applySql('SELECT * FROM (', false);
        $this->applySql($this->getSELECT(), false);
        $this->applySql($this->getFROM(), false);
        $this->applySql(') grouped_rows', false);
        $this->applySql($this->getWHERE(), false);
        $this->applySql($this->getORDER(), false);
        $this->applySql("LIMIT $this->limit", false);

        return $this->sql;
    }

    /**
     * All-zero rows are dropped and the row cap applied in SQL, not in PHP: the
     * stats_groups CTE yields one row per dimension value of the whole team (tens
     * of thousands of entry pages for large teams), and hydrating them all just to
     * take() a handful exhausted the request memory limit.
     */
    protected function getWHERE(): string
    {
        $nonZeroColumns = ['visitors', 'net'];

        if ($this->trackablePerson === TrackablePerson::USER) {
            $nonZeroColumns = ['dau', 'reg', ...$nonZeroColumns];
        }

        if ($this->withMrr) {
            $nonZeroColumns[] = 'mrr';
        }

        if ($this->withNau) {
            $nonZeroColumns[] = 'nau';
        }

        return 'WHERE '.collect($nonZeroColumns)->map(fn (string $column): string => "$column <> 0")->implode(' OR ');
    }

    protected function getCTE(): string
    {
        return "WITH stats_groups AS (
            SELECT id as type_id, name FROM $this->statsTable stats WHERE :teamCondition
            UNION ALL
            SELECT $this->typeIdFallback as type_id, 'Direct / Unknown' as name
        )";
    }

    /**
     * dau: unique active accounts, matching the headline pd_dau definition
     * (the registration login counts as active).
     */
    protected function getPD_DAU(): string
    {
        return "pd_dau AS (
            SELECT COALESCE(su.$this->statsDBType, $this->typeIdFallback) as type_id, COUNT(DISTINCT ".$this->getDistinctFields(['stats.stats_user_id', 'stats.project_id']).") AS count
            FROM stats_logins stats
            :trackingUserJoinConditions AND :projectCondition
            WHERE :projectCondition AND stats.time >= ':dauEndDateStart' AND stats.time <= ':dauEndDateEnd' and stats.counter >= 1
            GROUP BY type_id
        )";
    }

    /**
     * lifetime regs
     */
    protected function getLT_REG(): string
    {
        return "lt_reg AS (
            SELECT COALESCE(stats.$this->statsDBType, $this->typeIdFallback) as type_id, COUNT(*) as count
            FROM stats_users stats
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :trackingCondition :eventConditionUser :propertyConditionUser :cohortConditionUser
            GROUP BY type_id
        )";
    }

    /**
     * lifetime visitors
     */
    protected function getLT_VISITORS(): string
    {
        if ($this->withViews) {
            return "lt_visitors AS (
                SELECT COALESCE(stats.$this->statsDBType, $this->typeIdFallback) as type_id, COUNT(*) as count,
                    COALESCE(SUM(vv.views), 0) AS views,
                    COUNT(vv.views) AS viewers,
                    COUNT(*) FILTER (WHERE vv.views = 1) AS bounces
                FROM stats_visitors stats
                ".StatsAggregationsSqlBuilder::viewsJoin()."
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :trackingCondition :eventConditionVisitor :propertyConditionVisitor :cohortConditionVisitor
                GROUP BY type_id
            )";
        }

        return "lt_visitors AS (
            SELECT COALESCE(stats.$this->statsDBType, $this->typeIdFallback) as type_id, COUNT(*) as count
            FROM stats_visitors stats
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :trackingCondition :eventConditionVisitor :propertyConditionVisitor :cohortConditionVisitor
            GROUP BY type_id
        )";
    }

    /**
     * lifetime net revenue
     */
    protected function getLT_NET(): string
    {
        return "lt_net AS (
            SELECT type_id, SUM(count) as count
            FROM (
                SELECT COALESCE(su.$this->statsDBType, $this->typeIdFallback) as type_id, SUM(stats.net) as count
                FROM stats_payments stats
                :trackingUserJoinConditions AND :projectCondition
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                GROUP BY type_id
                UNION ALL
                SELECT COALESCE(sv.$this->statsDBType, $this->typeIdFallback) as type_id, SUM(stats.net) as count
                FROM stats_payments stats
                :trackingVisitorJoinConditions AND :projectCondition
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                GROUP BY type_id
            ) as combined_results
            GROUP BY type_id
        )";
    }

    /**
     * Active recurring revenue per dimension as an as-of snapshot. USER-only:
     * subscriptions imply registrations and revenue is attributed to the user.
     */
    protected function getLT_MRR(): string
    {
        $typeId = "COALESCE(su.$this->statsDBType, $this->typeIdFallback) AS type_id";

        return 'lt_mrr AS (
            SELECT
                lr.type_id AS type_id,
                COUNT(*) AS subscribers,
                COALESCE(SUM('.$this->mrrMonthlyAmount().'), 0) AS mrr'
            .$this->activeSubscriptionsFrom("<= ':asOfDate'", "':monthWindowStart'", "':yearWindowStart'", [$typeId]).'
            GROUP BY lr.type_id
        )';
    }

    protected function getLT_TIME_TO_CONVERT(): string
    {
        $median = $this->medianDaysBetween('sv.time', 'stats.time');

        return "lt_time_to_convert AS (
            SELECT COALESCE(stats.$this->statsDBType, $this->typeIdFallback) as type_id, $median AS time_to_convert
            FROM stats_users stats
            JOIN stats_visitors sv
                ON sv.id = stats.stats_visitor_id AND sv.team_id = stats.team_id AND sv.project_id = stats.project_id
            WHERE :projectCondition AND stats.registered = true AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :trackingCondition :eventConditionUser :propertyConditionUser :cohortConditionUser
            GROUP BY type_id
        )";
    }

    protected function hasRetention(): bool
    {
        return $this->retentionCheckpoints !== [];
    }

    /**
     * New Active Users per dimension: users whose first return login (counter = 2,
     * unique per user) falls in the range, attributed via the user row like pd_dau.
     */
    protected function getLT_NAU(): string
    {
        return "lt_nau AS (
            SELECT COALESCE(su.$this->statsDBType, $this->typeIdFallback) as type_id, COUNT(DISTINCT ".$this->getDistinctFields(['stats.stats_user_id', 'stats.project_id']).") AS count
            FROM stats_logins stats
            :trackingUserJoinConditions AND :projectCondition
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND stats.counter = 2
            GROUP BY type_id
        )";
    }

    /**
     * Unbounded retention per dimension value: a user counts as retained at
     * checkpoint N when their latest return login (counter >= 2) happened at or
     * after registration + N days, so one MAX(sl.time) per user answers every
     * checkpoint. Denominators are right-censored to users registered at least
     * N days before now; the visible Signups column stays the uncensored lt_reg.
     * Checkpoint offsets are trusted ints from StatsRangeType, not user input.
     */
    protected function getLT_RETENTION(): string
    {
        $pairs = collect($this->retentionCheckpoints)->map(function (int $days, int $index): string {
            $checkpoint = $index + 1;

            return "COUNT(*) FILTER (WHERE stats.time <= ':nowCap'::timestamp - INTERVAL '$days days') AS observable_c$checkpoint,
                COUNT(*) FILTER (WHERE stats.time <= ':nowCap'::timestamp - INTERVAL '$days days' AND lr.last_return >= stats.time + INTERVAL '$days days') AS retained_c$checkpoint";
        })->implode(",\n                ");

        return "lt_retention AS (
            SELECT COALESCE(stats.$this->statsDBType, $this->typeIdFallback) as type_id,
                $pairs
            FROM stats_users stats
            LEFT JOIN LATERAL (
                SELECT MAX(sl.time) AS last_return
                FROM stats_logins sl
                WHERE sl.stats_user_id = stats.id AND sl.project_id = stats.project_id AND sl.counter >= 2
            ) lr ON TRUE
            WHERE :projectCondition AND stats.registered = true AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :trackingCondition :eventConditionUser :propertyConditionUser :cohortConditionUser
            GROUP BY type_id
        )";
    }

    protected function getSELECT(): string
    {
        $sql = 'SELECT
            sg.type_id as type_id,
            sg.name as name,';

        if ($this->trackablePerson === TrackablePerson::USER) {
            $sql .= '
                COALESCE(lt_reg.count, 0) as reg,
                ROUND(COALESCE((lt_reg.count * 100.0 / NULLIF(lt_visitors.count, 0)), 0), 2) AS cr,
                COALESCE(pd_dau.count, 0) AS dau,
                ROUND(COALESCE((lt_net.count / NULLIF(lt_reg.count, 0))::numeric, 0), 2) AS arpu,
                ROUND(COALESCE((lt_net.count / NULLIF(pd_dau.count, 0))::numeric, 0), 2) AS arpdau,
                ROUND(COALESCE(pd_dau.count * 100.0 / NULLIF(lt_reg.count, 0), 0), 2) AS reg2dau,';
        }

        $sql .= '
            COALESCE(lt_visitors.count, 0) AS visitors,
            COALESCE(lt_net.count, 0) AS net,
            ROUND(COALESCE((lt_net.count / NULLIF(lt_visitors.count, 0))::numeric, 0), 4) AS arpv';

        if ($this->withViews) {
            // Ratios use viewers, not visitors: visitors without any reported view (before
            // the client sent views, or no qualifying page) must not dilute them.
            $sql .= ',
                COALESCE(lt_visitors.views, 0) AS views,
                COALESCE(lt_visitors.viewers, 0) AS viewers,
                COALESCE(lt_visitors.bounces, 0) AS bounces,
                ROUND(COALESCE(lt_visitors.views * 1.0 / NULLIF(lt_visitors.viewers, 0), 0), 2) AS vpv,
                ROUND(COALESCE(lt_visitors.bounces * 100.0 / NULLIF(lt_visitors.viewers, 0), 0), 2) AS bounce_rate';
        }

        if ($this->withMrr) {
            $sql .= ',
                COALESCE(lt_mrr.mrr, 0) AS mrr,
                COALESCE(lt_mrr.subscribers, 0) AS subscribers,
                ROUND(COALESCE((lt_mrr.mrr / NULLIF(lt_mrr.subscribers, 0))::numeric, 0), 2) AS arps';
        }

        if ($this->withTimeToConvert) {
            $sql .= ',
                ROUND(lt_time_to_convert.time_to_convert::numeric, 1) AS time_to_convert';
        }

        if ($this->withNau) {
            $sql .= ',
                COALESCE(lt_nau.count, 0) AS nau';
        }

        if ($this->hasRetention()) {
            // deliberately no COALESCE: NULL = checkpoint not observable yet (censored)
            foreach (array_keys($this->retentionCheckpoints) as $index) {
                $checkpoint = $index + 1;
                $sql .= ",
                ROUND(lt_retention.retained_c$checkpoint * 100.0 / NULLIF(lt_retention.observable_c$checkpoint, 0), 1) AS ret_c$checkpoint";
            }
        }

        return $sql;
    }

    protected function getFROM(): string
    {
        $sql = 'FROM
            stats_groups sg';

        if ($this->trackablePerson === TrackablePerson::USER) {
            $sql .= '
                LEFT JOIN pd_dau ON sg.type_id = pd_dau.type_id
                LEFT JOIN lt_reg ON sg.type_id = lt_reg.type_id';
        }

        $sql .= '
            LEFT JOIN lt_visitors ON sg.type_id = lt_visitors.type_id
            LEFT JOIN lt_net ON sg.type_id = lt_net.type_id';

        if ($this->withMrr) {
            $sql .= '
                LEFT JOIN lt_mrr ON sg.type_id = lt_mrr.type_id';
        }

        if ($this->withTimeToConvert) {
            $sql .= '
                LEFT JOIN lt_time_to_convert ON sg.type_id = lt_time_to_convert.type_id';
        }

        if ($this->withNau) {
            $sql .= '
                LEFT JOIN lt_nau ON sg.type_id = lt_nau.type_id';
        }

        if ($this->hasRetention()) {
            $sql .= '
                LEFT JOIN lt_retention ON sg.type_id = lt_retention.type_id';
        }

        return $sql;
    }

    protected function getORDER(): string
    {
        return $this->getGroupedOrderBy($this->statsSort, $this->trackablePerson);
    }
}
