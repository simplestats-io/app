<?php

namespace App\Builder;

use App\Enums\TrackablePerson;
use App\Services\StatsService;

class StatsAggregationsSqlBuilder extends BaseSqlBuilder
{
    protected int $nauLoginCounter;

    protected int $daapuWindowDays;

    /**
     * When true, the lifetime (lt_*) CTEs emit a constant 0 instead of scanning the whole
     * history before the range start. The caller then seeds the baseline from the cached
     * predecessor bucket (lazy carry-forward).
     */
    public function __construct(
        protected TrackablePerson $trackablePerson,
        protected bool $seedLtBaseline = false,
    ) {
        $this->nauLoginCounter = StatsService::NAU_LOGIN_COUNTER;
        $this->daapuWindowDays = StatsService::DAAPU_WINDOW_DAYS;
    }

    public function build(): string
    {
        $this->applySql($this->getCTE());

        if ($this->trackablePerson === TrackablePerson::USER) {
            // per date
            $this->applySql($this->getPD_REG());
            $this->applySql($this->getPD_TAU());
            $this->applySql($this->getPD_PU());
            $this->applySql($this->getPD_NAU());
            $this->applySql($this->getPD_NAPU());
            $this->applySql($this->getPD_DAU());
            $this->applySql($this->getPD_DAPU());
            $this->applySql($this->getPD_DAAPU());
            // lifetime
            $this->applySql($this->getLT_REG());
            $this->applySql($this->getLT_TAU());
            $this->applySql($this->getLT_PU());
        }

        $this->applySql($this->getPD_VISITORS());
        $this->applySql($this->getPD_GROSS());
        $this->applySql($this->getPD_NET());

        $this->applySql($this->getLT_VISITORS());
        $this->applySql($this->getLT_NET(), false);

        $this->applySql($this->getSELECT(), false);
        $this->applySql($this->getFROM(), false);
        $this->applySql($this->getORDER(), false);

        return $this->sql;
    }

    protected function getCTE(): string
    {
        return 'WITH RECURSIVE date_sequence AS (
            SELECT '.$this->getDateSelect().' AS date_seq
            UNION ALL
            SELECT '.$this->getDateInterval('add', 'date_seq', 1, ':intervalType')."
            FROM date_sequence
            WHERE date_seq < ':endDateStart'
        )";
    }

    /**
     * number of registrations
     */
    protected function getPD_REG(): string
    {
        return 'pd_reg_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat')." AS date, COUNT(*) AS count
            FROM stats_users stats
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :trackingCondition :eventConditionUser :propertyConditionUser :cohortConditionUser
            GROUP BY date
        )";
    }

    /**
     * number of total actives
     */
    protected function getPD_TAU(): string
    {
        return 'pd_tau_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat')." AS date, COUNT(*) AS count
            FROM stats_logins stats
            :trackingUserJoinConditions
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND stats.counter = $this->nauLoginCounter
            GROUP BY date
        )";
    }

    /**
     * paying user
     */
    protected function getPD_PU(): string
    {
        return 'pd_pu_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat')." AS date, COUNT(*) AS count
            FROM stats_payments stats
            :trackingUserJoinConditions
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND stats.counter = 1
            GROUP BY date
        )";
    }

    /**
     * nau: Number of unique accounts who returned on this day (=2nd login) after the registration (=1st login)
     */
    protected function getPD_NAU(): string
    {
        return 'pd_nau_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat')." AS date, COUNT(*) AS count
            FROM stats_logins stats
            :trackingUserJoinConditions
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND stats.counter = $this->nauLoginCounter
            GROUP BY date
        )";
    }

    /**
     * napu: Number of unique accounts who did their first payment on this day
     */
    protected function getPD_NAPU(): string
    {
        return 'pd_napu_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat')." AS date, COUNT(*) AS count
            FROM stats_payments stats
            :trackingUserJoinConditions
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND stats.counter = 1
            GROUP BY date
        )";
    }

    /**
     * dau: Number of unique accounts who logged in or registered on this day. Uses
     * counter >= 1 (any login, including the registration login) to match the
     * standard DAU definition used by Amplitude/Mixpanel/GA4 and the dapu/daapu
     * siblings below; only nau (the "first return" event) keeps counter = 2.
     */
    protected function getPD_DAU(): string
    {
        return 'pd_dau_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat').' AS date, COUNT(DISTINCT '.$this->getDistinctFields(['stats.stats_user_id', 'stats.project_id']).") AS count
            FROM stats_logins stats
            :trackingUserJoinConditions
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND stats.counter >= 1
            GROUP BY date
        )";
    }

    /**
     * dapu: Number of unique accounts who logged in or registered on this day and did at least one payment over lifetime (including today)
     */
    protected function getPD_DAPU(): string
    {
        return 'pd_dapu_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat').' AS date, COUNT(DISTINCT '.$this->getDistinctFields(['stats.stats_user_id', 'stats.project_id']).") AS count
            FROM stats_logins stats
            :trackingUserJoinConditions
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND EXISTS (
                SELECT 1
                FROM stats_payments sp
                WHERE stats.stats_user_id = sp.stats_user_id
                    AND stats.project_id = sp.project_id
            )
            GROUP BY date
        )";
    }

    /**
     * daapu: Number of unique accounts who logged in or registered on this day and did at least one payment in the last 20 days (including today)
     */
    protected function getPD_DAAPU(): string
    {
        return 'pd_daapu_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat').' AS date, COUNT(DISTINCT '.$this->getDistinctFields(['stats.stats_user_id', 'stats.project_id']).") AS count
            FROM stats_logins stats
            :trackingUserJoinConditions
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' AND EXISTS (
                SELECT 1
                FROM stats_payments sp
                WHERE stats.stats_user_id = sp.stats_user_id
                    AND stats.project_id = sp.project_id
                    AND sp.time >= ".$this->getDateInterval('sub', 'stats.time', $this->daapuWindowDays, 'day').'
                    AND sp.time <= stats.time
            )
            GROUP BY date
        )';
    }

    /**
     * Zero baseline for a lifetime CTE: keeps the same shape (one row per date in
     * the sequence) but skips the historical scan, so the caller can carry the
     * baseline forward from the cached predecessor bucket instead.
     */
    protected function getLT_ZERO(string $cteName): string
    {
        return $cteName.' AS (
            SELECT '.$this->getDateFormat('ds.date_seq', ':dateFormat').' AS date, 0 AS count
            FROM date_sequence ds
        )';
    }

    /**
     * lifetime regs
     */
    protected function getLT_REG(): string
    {
        if ($this->seedLtBaseline) {
            return $this->getLT_ZERO('lt_reg_cte');
        }

        return 'lt_reg_cte AS (
            SELECT
                '.$this->getDateFormat('ds.date_seq', ':dateFormat')." AS date,
                (
                    SELECT COUNT(*)
                    FROM stats_users stats
                    WHERE :projectCondition AND stats.time < ':startDateStart' :trackingCondition :eventConditionUser :propertyConditionUser :cohortConditionUser
                ) AS count
            FROM date_sequence ds
        )";
    }

    /**
     * lifetime total actives
     */
    protected function getLT_TAU(): string
    {
        if ($this->seedLtBaseline) {
            return $this->getLT_ZERO('lt_tau_cte');
        }

        return 'lt_tau_cte AS (
            SELECT
                '.$this->getDateFormat('ds.date_seq', ':dateFormat')." AS date,
                (
                    SELECT COUNT(*)
                    FROM stats_logins stats
                    :trackingUserJoinConditions
                    WHERE :projectCondition AND stats.time < ':startDateStart' AND stats.counter = $this->nauLoginCounter
                ) AS count
            FROM date_sequence ds
        )";
    }

    /**
     * lifetime paying user
     */
    protected function getLT_PU(): string
    {
        if ($this->seedLtBaseline) {
            return $this->getLT_ZERO('lt_pu_cte');
        }

        return 'lt_pu_cte AS (
            SELECT
                '.$this->getDateFormat('ds.date_seq', ':dateFormat')." AS date,
                (
                    SELECT COUNT(*)
                    FROM stats_payments stats
                    :trackingUserJoinConditions
                    WHERE :projectCondition AND stats.time < ':startDateStart' AND stats.counter = 1
                ) AS count
            FROM date_sequence ds
        )";
    }

    protected function getPD_VISITORS(): string
    {
        return 'pd_visitors_cte AS (
            SELECT '.$this->getDateFormat('stats.time', ':dateFormat').' AS date, COUNT(*) AS count,
                COALESCE(SUM(vv.views), 0) AS views,
                COUNT(vv.views) AS viewers,
                COUNT(*) FILTER (WHERE vv.views = 1) AS bounces
            FROM stats_visitors stats
            '.self::viewsJoin()."
            WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd' :trackingCondition :eventConditionVisitor :propertyConditionVisitor :cohortConditionVisitor
            GROUP BY date
        )";
    }

    /**
     * The join is 1:1 per visitor, so COUNT(*) stays the visitor count and every filter on
     * the stats alias narrows the views too.
     */
    public static function viewsJoin(): string
    {
        return "LEFT JOIN stats_visitor_views vv ON vv.stats_visitor_id = stats.id
                AND :viewsProjectCondition AND vv.time >= ':startDateStart' AND vv.time <= ':endDateEnd'";
    }

    protected function getPD_GROSS(): string
    {
        return 'pd_gross_cte AS (
            SELECT date, SUM(count) AS count
            FROM (
                SELECT '.$this->getDateFormat('stats.time', ':dateFormat')." AS date, SUM(stats.gross) AS count
                FROM stats_payments stats
                :trackingUserJoinConditions
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                GROUP BY date
                UNION ALL
                SELECT ".$this->getDateFormat('stats.time', ':dateFormat')." AS date, SUM(stats.gross) AS count
                FROM stats_payments stats
                :trackingVisitorJoinConditions
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                GROUP BY date
            ) AS combined_results
            GROUP BY date
        )";
    }

    protected function getPD_NET(): string
    {
        return 'pd_net_cte AS (
            SELECT date, SUM(count) AS count
            FROM (
                SELECT '.$this->getDateFormat('stats.time', ':dateFormat')." AS date, SUM(stats.net) AS count
                FROM stats_payments stats
                :trackingUserJoinConditions
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                GROUP BY date
                UNION ALL
                SELECT ".$this->getDateFormat('stats.time', ':dateFormat')." AS date, SUM(stats.net) AS count
                FROM stats_payments stats
                :trackingVisitorJoinConditions
                WHERE :projectCondition AND stats.time >= ':startDateStart' AND stats.time <= ':endDateEnd'
                GROUP BY date
            ) AS combined_results
            GROUP BY date
        )";
    }

    protected function getLT_VISITORS(): string
    {
        if ($this->seedLtBaseline) {
            return $this->getLT_ZERO('lt_visitor_cte');
        }

        return 'lt_visitor_cte AS (
            SELECT
                '.$this->getDateFormat('ds.date_seq', ':dateFormat')." AS date,
                (
                    SELECT COUNT(*)
                    FROM stats_visitors stats
                    WHERE :projectCondition AND stats.time < ':startDateStart' :trackingCondition :eventConditionVisitor :propertyConditionVisitor :cohortConditionVisitor
                ) AS count
            FROM date_sequence ds
        )";
    }

    protected function getLT_NET(): string
    {
        if ($this->seedLtBaseline) {
            return $this->getLT_ZERO('lt_net_cte');
        }

        return 'lt_net_cte AS (
            SELECT date, SUM(count) AS count
            FROM (
                SELECT
                    '.$this->getDateFormat('ds.date_seq', ':dateFormat')." AS date,
                    (
                        SELECT SUM(stats.net)
                        FROM stats_payments stats
                        :trackingUserJoinConditions
                        WHERE :projectCondition AND stats.time < ':startDateStart'
                    ) AS count
                FROM date_sequence ds
                UNION ALL
                SELECT
                    ".$this->getDateFormat('ds.date_seq', ':dateFormat')." AS date,
                    (
                        SELECT SUM(stats.net)
                        FROM stats_payments stats
                        :trackingVisitorJoinConditions
                        WHERE :projectCondition AND stats.time < ':startDateStart'
                    ) AS count
                FROM date_sequence ds
            ) AS combined_results
            GROUP BY date
        )";
    }

    protected function getSELECT(): string
    {
        $sql = 'SELECT
            '.$this->getDateFormat('ds.date_seq', ':dateFormat').' AS date,
            '.$this->getDateFormat('ds.date_seq', ':dateFormatClear').' AS date_clear,';

        if ($this->trackablePerson === TrackablePerson::USER) {
            $sql .= '
                COALESCE(pd_reg_cte.count, 0) AS pd_reg,
                COALESCE(pd_tau_cte.count, 0) AS pd_tau,
                COALESCE(pd_pu_cte.count, 0) AS pd_pu,
                COALESCE(pd_nau_cte.count, 0) AS pd_nau,
                COALESCE(pd_napu_cte.count, 0) AS pd_napu,
                COALESCE(pd_dau_cte.count, 0) AS pd_dau,
                COALESCE(pd_dapu_cte.count, 0) AS pd_dapu,
                COALESCE(pd_daapu_cte.count, 0) AS pd_daapu,
                COALESCE(lt_reg_cte.count, 0) AS lt_reg,
                COALESCE(lt_pu_cte.count, 0) AS lt_pu,
                COALESCE(lt_tau_cte.count, 0) AS lt_tau,';
        }

        $sql .= '
            COALESCE(pd_visitors_cte.count, 0) AS pd_visitor,
            COALESCE(pd_visitors_cte.views, 0) AS pd_view,
            COALESCE(pd_visitors_cte.viewers, 0) AS pd_viewer,
            COALESCE(pd_visitors_cte.bounces, 0) AS pd_bounce,
            COALESCE(pd_gross_cte.count, 0) AS pd_gross,
            COALESCE(pd_net_cte.count, 0) AS pd_net,
            COALESCE(lt_visitor_cte.count, 0) AS lt_visitor,
            COALESCE(lt_net_cte.count, 0) AS lt_net';

        return $sql;
    }

    protected function getFROM(): string
    {
        $sql = 'FROM
            date_sequence ds';

        if ($this->trackablePerson === TrackablePerson::USER) {
            $sql .= '
                LEFT JOIN pd_reg_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_reg_cte.date
                LEFT JOIN pd_tau_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_tau_cte.date
                LEFT JOIN pd_pu_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_pu_cte.date
                LEFT JOIN pd_nau_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_nau_cte.date
                LEFT JOIN pd_napu_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_napu_cte.date
                LEFT JOIN pd_dau_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_dau_cte.date
                LEFT JOIN pd_dapu_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_dapu_cte.date
                LEFT JOIN pd_daapu_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_daapu_cte.date
                LEFT JOIN lt_reg_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = lt_reg_cte.date
                LEFT JOIN lt_pu_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = lt_pu_cte.date
                LEFT JOIN lt_tau_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = lt_tau_cte.date';
        }

        $sql .= '
            LEFT JOIN pd_visitors_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_visitors_cte.date
            LEFT JOIN pd_gross_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_gross_cte.date
            LEFT JOIN pd_net_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = pd_net_cte.date
            LEFT JOIN lt_visitor_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = lt_visitor_cte.date
            LEFT JOIN lt_net_cte ON '.$this->getDateFormat('ds.date_seq', ':dateFormat').' = lt_net_cte.date';

        return $sql;
    }

    protected function getORDER(): string
    {
        return 'ORDER BY date_clear DESC;';
    }
}
