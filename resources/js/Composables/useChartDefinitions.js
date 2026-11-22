import {formatDate} from "@/Helpers/NumberHelpers.js";
import {getKpiDefinitions} from "@/Data/KpiDefinitions.js";
import {usePage} from "@inertiajs/vue3";
import {useUsage} from "@/Composables/useUsage.js";
import {useFlags} from "@/Composables/useFlags.js";

// A registration cohort is a set of registered users and has no visitor leg, so
// visitor-derived KPIs (unique visitors, conversion rate, revenue per visitor)
// are not applicable and are dropped from the funnel while a cohort is active.
const COHORT_NA_KPIS = ['pd_visitor', 'pd_cr', 'lt_arpv', 'pd_view', 'pd_vpv', 'pd_bounce_rate'];

const PAGE_VIEW_KPIS = ['pd_view', 'pd_vpv', 'pd_bounce_rate'];

// KPIs sourced from the MRR snapshot series. The per-plan breakdown (Metric detail
// view) only applies to these; everything else uses the dimension StatsGroups.
const MRR_KPI_KEYS = ['mrr', 'subscribers', 'arps'];

// Curated breakdown columns per KPI for the metric detail view: the primary is
// highlighted and becomes the default sort, the companions give a ratio its inputs.
// KPIs not listed here (and bit-dependent pd_net, see breakdownFor) fall back to the
// breakdown's default columns.
const BREAKDOWN_SETS = {
    pd_visitor:  {columns: ['visitors', 'reg', 'cr'],      primary: 'visitors'},
    pd_reg:      {columns: ['visitors', 'reg', 'cr'],      primary: 'reg'},
    pd_cr:       {columns: ['visitors', 'reg', 'cr'],      primary: 'cr'},
    lt_arpv:     {columns: ['visitors', 'net', 'arpv'],    primary: 'arpv'},
    pd_view:     {columns: ['visitors', 'views', 'vpv'],   primary: 'views'},
    pd_vpv:      {columns: ['visitors', 'views', 'vpv'],   primary: 'vpv'},
    pd_bounce_rate: {columns: ['visitors', 'views', 'bounce_rate'], primary: 'bounce_rate'},
    lt_arpu:     {columns: ['reg', 'net', 'arpu'],         primary: 'arpu'},
    pd_arpdau:   {columns: ['dau', 'net', 'arpdau'],       primary: 'arpdau'},
    pd_dau:      {columns: ['reg', 'dau', 'ret_c2'],       primary: 'dau'},
    // NAU plus the two longer retention checkpoints (staying power). The first
    // checkpoint is skipped: "came back at all" is what NAU itself already says.
    pd_nau:      {columns: ['reg', 'nau', 'ret_c2', 'ret_c3'], primary: 'nau'},
    lt_reg2dau:  {columns: ['reg', 'dau', 'reg2dau'],      primary: 'reg2dau'},
    time_to_convert: {columns: ['visitors', 'reg', 'time_to_convert'], primary: 'time_to_convert'},
    mrr:         {columns: ['subscribers', 'mrr', 'arps'], primary: 'mrr'},
    subscribers: {columns: ['subscribers', 'mrr', 'arps'], primary: 'subscribers'},
    arps:        {columns: ['subscribers', 'mrr', 'arps'], primary: 'arps'},
};

// The curated dashboard overview: a fixed ~9 KPIs (3 per section) instead of the
// full catalog, so the small-multiples dashboard stays a clean monitoring surface.
// Each section lists candidate keys in priority order; the first 3 that the project
// actually tracks are shown. This auto-adapts per project type, e.g. Revenue becomes
// MRR/Net/ARPU for a subscription project and Net/ARPU/ARPV for a payments-only one,
// Activity becomes XAU/Paying/Reg-to-Pay with payments and XAU/NAU/Reg-to-Active
// without. The full catalog stays reachable via the metric detail view's left menu.
const OVERVIEW_DEFS = [
    // Page views fill the Acquisition row of projects without registrations.
    {name: 'Acquisition', keys: ['pd_visitor', 'pd_reg', 'pd_cr', 'pd_view', 'pd_bounce_rate']},
    {name: 'Revenue', keys: ['mrr', 'pd_net', 'lt_arpu', 'lt_arpv']},
    {name: 'Activity', keys: ['pd_dau', 'lt_pu', 'lt_reg2pu', 'pd_nau', 'lt_reg2dau']},
];

const OVERVIEW_SECTION_SIZE = 3;

// Intra-period change in percent for a serie's headline indicator. Mirrors the logic
// that used to live in StatsIndicator.vue so small charts and headline numbers match.
const computeDelta = (serie, hasCurrent) => {
    const d = serie.data;
    let highCurrent = d[d.length - 1] > d[d.length - 2];

    let latest = d[d.length - 1];
    let previous = serie.dataPrev.length ? serie.dataPrev[serie.dataPrev.length - 1] : d[d.length - 2];

    if (hasCurrent && ! highCurrent) {
        latest = d[d.length - 2];
        previous = serie.dataPrev.length ? serie.dataPrev[serie.dataPrev.length - 2] : d[d.length - 3];
    }

    if (latest === null || previous === null || latest === undefined || previous === undefined) {
        return 0;
    }

    // Divide by |previous| so the sign always reflects the direction of movement,
    // even when the baseline is negative (e.g. net churn below zero).
    let increase = (((latest - previous) / Math.abs(previous)) * 100);

    if (isNaN(increase)) {
        increase = 0;
    }

    if (increase === Infinity) {
        increase = 100;
    }

    return increase;
};

// Resolve a serie into the 1-2 entry array a StatsChartCanvas plots: the primary
// serie plus, when a comparison is active, the previous-period twin.
export function resolveChartSeries(serie) {
    const resolved = [{...serie}];

    if (serie.dataPrev.length) {
        resolved[1] = {
            ...serie,
            data: serie.dataPrev,
            dates: serie.datesPrev,
            name: serie.namePrev,
            nameSmall: serie.nameSmallPrev,
            color: serie.colorPrev,
        };
    }

    return resolved;
}

export function useChartDefinitions(ctx) {
    const page = usePage();

    const kpiDefs = getKpiDefinitions(ctx.filters.range_type);

    // The revenue page reuses this composable for its MRR/churn KPIs but never loads the
    // period-summed stats (only StatsIndexDataProvider builds them), so default to empty:
    // the period chartDefs then yield nothing and only the MRR/engagement/churn builders run.
    const periodStats = ctx.teamStats.stats ?? {};

    const isDarkMode = document.documentElement.classList.contains('dark');

    const cohortActive = !! ctx.filters.cohort_start;

    // range_start is the period's end anchor; if it predates the cohort's first
    // signup, the whole observation window sits before the cohort exists and every
    // metric is empty. Surface that instead of letting it look like "no activity".
    const cohortStartsAfterPeriod = cohortActive
        && !! ctx.filters.range_start
        && new Date(ctx.filters.range_start) < new Date(ctx.filters.cohort_start.slice(0, 10));

    const extractStatsData4Chart = (statsGroups) => {
        const data = Object.values(periodStats).reduce((acc, item, key) => {
            for (let group of statsGroups) {
                if (!(group.key in acc.series)) {
                    acc.series[group.key] = {
                        // chart specific
                        data: [],
                        dataPrev: [],
                        dates: [],
                        datesPrev: [],
                        name: group.title ?? group.key,
                        nameSmall: group.titleSmall ?? group.title ?? group.key,
                        color: isDarkMode ? '#00A3E0' : '#93c5fd',
                        // extras...
                        key: group.key,
                        formatter: group.formatter,
                        decimals: group.decimals,
                        indicator: group.indicatorCallback(),
                    };

                    if (ctx.teamStats.statsPrev) {
                        acc.series[group.key].namePrev = 'Previous ' + acc.series[group.key].name;
                        acc.series[group.key].nameSmallPrev = 'Previous ' + acc.series[group.key].nameSmall;
                        acc.series[group.key].colorPrev = '#a3b8d3';
                    }

                    if (group.tooltipExtra) {
                        acc.series[group.key].tooltipExtra = group.tooltipExtra.map(t => ({
                            label: t.label,
                            formatter: t.formatter ?? 'currency',
                            data: [],
                        }));
                    }
                }

                acc.series[group.key].data.push(item[group.key]);
                acc.series[group.key].dates.push(item.date_clear);

                if (acc.series[group.key].tooltipExtra) {
                    group.tooltipExtra.forEach((t, i) => acc.series[group.key].tooltipExtra[i].data.push(item[t.key]));
                }

                if (ctx.teamStats.statsPrev) {
                    acc.series[group.key].dataPrev.push(ctx.teamStats.statsPrev[key][group.key]);
                    acc.series[group.key].datesPrev.push(ctx.teamStats.statsPrev[key].date);
                }
            }

            acc.categories.push(formatDate(item.date, ctx.filters.range_type));

            return acc;
        }, { series: {}, categories: [] });

        data.series = Object.values(data.series).map(item => ({
            ...item,
            data: item.data.reverse(),
            dates: item.dates.reverse(),
            dataPrev: item.dataPrev.reverse(),
            datesPrev: item.datesPrev.reverse(),
            tooltipExtra: item.tooltipExtra
                ? item.tooltipExtra.map(t => ({...t, data: [...t.data].reverse()}))
                : undefined,
        }));

        data.categories.reverse();

        return data;
    };

    let statsArray = Object.values(periodStats);
    let statsSum = {};
    let statsSumLength = {};
    statsArray.forEach(item => {
        Object.keys(item).forEach(key => {
            // null = not measured (e.g. before the first page view), so an all-null KPI shows "-" instead of 0.
            if (key === 'date' || key === 'date_clear' || item[key] === null) {
                return;
            }

            statsSum[key] = (statsSum[key] ?? 0) + item[key];
            statsSumLength[key] = (statsSumLength[key] ?? 0) + 1;
        });
    });

    // ARPS is derived from the MRR snapshot series; inject it per bucket (current and
    // comparison) so it can live in the period-based Average Revenue chart alongside ARPU/ARPV.
    const injectArps = (series, buckets) => {
        if (! series?.length || ! buckets?.length) {
            return;
        }

        const arpsByDate = {};
        series.forEach(item => { arpsByDate[item.date_clear] = item.subscribers > 0 ? item.mrr / item.subscribers : 0; });
        buckets.forEach(item => { item.arps = arpsByDate[item.date_clear] ?? 0; });
    };

    injectArps(ctx.mrrSeries, statsArray);
    injectArps(ctx.mrrSeriesPrev, ctx.teamStats.statsPrev ? Object.values(ctx.teamStats.statsPrev) : null);

    const lastStat = (key) => {
        if (! statsArray.length) {
            return 0;
        }

        let stat = statsArray.at(0);

        if (statsArray.length > 1) {
            let prevStat = statsArray.at(1);

            if (formatDate(stat.date, ctx.filters.range_type) === formatDate(page.props.rangeCurrent, ctx.filters.range_type) && stat[key] < prevStat[key]) {
                stat = prevStat;
            }
        }

        return stat[key];
    };

    function safeDevision(numerator, denominator) {
        return denominator == 0 ? 0 : numerator / denominator;
    }

    // MRR/Subscribers are point-in-time snapshots per bucket (their own series, not
    // part of the period-summed teamStats.stats), so they get a dedicated chart block
    // whose indicator is the latest snapshot, not a sum. When a comparison is active they
    // get a previous-period line from mrrSeriesPrev (the prior range's snapshots); Net
    // carries its comparison from teamStats.statsPrev. A single-bucket range draws a flat
    // line (value duplicated) instead of the 0 -> value ramp the period charts use,
    // because MRR is a stock and never starts at 0.
    const buildMrrChart = (mrrSeries) => {
        const ordered = [...mrrSeries].reverse();
        const orderedPrev = ctx.mrrSeriesPrev?.length ? [...ctx.mrrSeriesPrev].reverse() : null;
        const latest = mrrSeries[0];
        const color = isDarkMode ? '#00A3E0' : '#93c5fd';
        const dates = ordered.map(item => item.date_clear);

        const snapshotPrev = (key) => orderedPrev ? ordered.map((item, i) => orderedPrev[i]?.[key] ?? 0) : [];
        const snapshotDatesPrev = () => orderedPrev ? ordered.map((item, i) => orderedPrev[i]?.date) : [];

        const prevList = ctx.teamStats.statsPrev ? Object.values(ctx.teamStats.statsPrev) : null;
        const netByDate = {};
        const netPrevByDate = {};
        const prevDateByDate = {};
        Object.values(periodStats).forEach((item, i) => {
            netByDate[item.date_clear] = item.pd_net;
            if (prevList) {
                netPrevByDate[item.date_clear] = prevList[i]?.pd_net ?? 0;
                prevDateByDate[item.date_clear] = prevList[i]?.date;
            }
        });

        const seriesDefs = [
            {
                key: 'mrr', formatter: 'currency',
                data: ordered.map(item => item.mrr),
                dataPrev: snapshotPrev('mrr'),
                datesPrev: snapshotDatesPrev(),
                indicator: latest.mrr,
            },
            {
                key: 'subscribers', formatter: 'number',
                data: ordered.map(item => item.subscribers),
                dataPrev: snapshotPrev('subscribers'),
                datesPrev: snapshotDatesPrev(),
                indicator: latest.subscribers,
            },
            // Net is a period flow (from teamStats.stats) aligned onto the MRR snapshot buckets so the headline revenue sits with MRR.
            {
                key: 'pd_net', formatter: 'currency',
                data: ordered.map(item => netByDate[item.date_clear] ?? 0),
                dataPrev: prevList ? ordered.map(item => netPrevByDate[item.date_clear] ?? 0) : [],
                datesPrev: prevList ? ordered.map(item => prevDateByDate[item.date_clear]) : [],
                indicator: statsSum.pd_net,
            },
        ];

        // ARPS (MRR per subscriber) is a snapshot-derived recurring metric. The dashboard
        // sources it from the period stats (Average Revenue chart), but the revenue page has
        // no period stats, so it opts in here to surface ARPS from the same MRR snapshot. Off
        // elsewhere to avoid a duplicate ARPS entry in the metric menu.
        if (ctx.includeMrrArps) {
            seriesDefs.splice(2, 0, {
                key: 'arps', formatter: 'currency',
                data: ordered.map(item => item.subscribers > 0 ? item.mrr / item.subscribers : 0),
                dataPrev: orderedPrev ? ordered.map((item, i) => (orderedPrev[i]?.subscribers > 0 ? orderedPrev[i].mrr / orderedPrev[i].subscribers : 0)) : [],
                datesPrev: snapshotDatesPrev(),
                indicator: latest.subscribers > 0 ? latest.mrr / latest.subscribers : 0,
            });
        }

        const series = seriesDefs.map(def => {
            const hasPrev = !! def.dataPrev?.length;
            const name = kpiDefs[def.key].short;
            const namePrev = hasPrev ? 'Previous ' + name : undefined;

            return {
                data: [...def.data],
                dataPrev: hasPrev ? [...def.dataPrev] : [],
                dates: [...dates],
                datesPrev: hasPrev ? [...def.datesPrev] : [],
                name,
                nameSmall: name,
                namePrev,
                nameSmallPrev: namePrev,
                color,
                colorPrev: hasPrev ? '#a3b8d3' : undefined,
                key: def.key,
                formatter: def.formatter,
                indicator: def.indicator,
            };
        });

        const categories = ordered.map(item => formatDate(item.date, ctx.filters.range_type));

        if (categories.length === 1) {
            categories[1] = categories[0];
            series.forEach(serie => {
                serie.data[1] = serie.data[0];
                serie.dates[1] = serie.dates[0];

                if (serie.dataPrev.length) {
                    serie.dataPrev[1] = serie.dataPrev[0];
                    serie.datesPrev[1] = serie.datesPrev[0];
                }
            });
        }

        const info = seriesDefs.map(def => {
            const kpiDef = kpiDefs[def.key];

            return `<div>
                        <div class="font-bold text-green-300">${kpiDef.long} (${kpiDef.short})</div>
                        <div>${kpiDef.description}</div>
                     </div>`;
        }).join('');

        return {
            name: 'Revenue',
            order: 2,
            group: seriesDefs.map(def => ({key: def.key, formatter: def.formatter, title: kpiDefs[def.key].short})),
            info,
            data: {series, categories},
        };
    };

    // Build a standalone chart group from a flat time series (newest-first) the way
    // buildMrrChart does for MRR snapshots: one serie per def, reversed to oldest-first,
    // indicator = latest bucket. No previous-period twin (comparison is not built for
    // these sources yet), so dataPrev/datesPrev stay empty. def.dataKey reads a differently
    // named source field (e.g. gross_churn_rate -> the gross_churn KPI). Per-bucket rates
    // can be null and are coalesced to 0; a single bucket draws a flat line (a rate or
    // snapshot never starts at 0, so no 0 -> value ramp like the period charts).
    const buildFlatSeriesChart = (name, order, series, defs) => {
        const ordered = [...series].reverse();
        const color = isDarkMode ? '#00A3E0' : '#93c5fd';
        const dates = ordered.map(item => item.date_clear);
        const latest = series[0];

        const built = defs.map(def => {
            const field = def.dataKey ?? def.key;
            const serieName = kpiDefs[def.key].short;

            return {
                data: ordered.map(item => item[field] ?? 0),
                dataPrev: [],
                dates: [...dates],
                datesPrev: [],
                name: serieName,
                nameSmall: serieName,
                color,
                key: def.key,
                formatter: def.formatter,
                decimals: def.decimals,
                suffix: def.suffix ?? null,
                indicator: latest[field] ?? 0,
            };
        });

        const categories = ordered.map(item => formatDate(item.date, ctx.filters.range_type));

        if (categories.length === 1) {
            categories[1] = categories[0];
            built.forEach(serie => {
                serie.data[1] = serie.data[0];
                serie.dates[1] = serie.dates[0];
            });
        }

        const info = defs.map(def => {
            const kpiDef = kpiDefs[def.key];

            return `<div>
                        <div class="font-bold text-green-300">${kpiDef.long} (${kpiDef.short})</div>
                        <div>${kpiDef.description}</div>
                     </div>`;
        }).join('');

        return {
            name,
            order,
            group: defs.map(def => ({key: def.key, formatter: def.formatter, title: kpiDefs[def.key].short})),
            info,
            data: {series: built, categories},
        };
    };

    const {currentProjectHasUsages} = useUsage(ctx.currentProject, ctx.usageBits);
    const {currentProjectHasFlags} = useFlags(ctx.currentProject, ctx.flagBits);

    // Paid-only: every registered user pays, so each "X" vs "paying X" KPI pair collapses
    // to the same value (and Reg-to-Pay to ~100%). The redundant paying-variants are hidden.
    const paidOnly = currentProjectHasFlags(['paid_only']);

    const showsRegistrations = currentProjectHasUsages(['users']);

    // No usage bit and views are on by default in the client, so a visitor project that
    // never received views shows the page view KPIs as an empty state hinting at the setup
    // (a missing worker stays noticeable). Views that stopped hide the KPIs instead: the
    // views join is skipped then, so an empty state would also blank past ranges with data.
    const pageViewsVisible = !! page.props.pageViews?.visible;
    const pageViewsNeverTracked = ! pageViewsVisible && ! page.props.pageViews?.since;

    // Net revenue's companions depend on what the project tracks: registration-aware
    // (Regs/ARPU) when users are tracked, visitor-aware (Visits/ARPV) for guest-checkout
    // projects that track payments without registrations.
    const breakdownFor = (key) => {
        if (key === 'pd_net') {
            return showsRegistrations
                ? {columns: ['reg', 'net', 'arpu'], primary: 'net'}
                : {columns: ['visitors', 'net', 'arpv'], primary: 'net'};
        }

        return BREAKDOWN_SETS[key] ?? null;
    };

    const subscriptionShown = currentProjectHasUsages(['payments', 'users']) && !! ctx.mrrSeries?.length;

    // A KPI shows when the project tracks the required bits. exceptBits excludes a KPI
    // when an additional bit is present (e.g. NAU only as the Activity fallback for
    // projects without payments). hideWhenSubscription drops the fallback Net in the
    // average-revenue chart when the recurring revenue chart already carries Net.
    const kpiVisible = (kpi) =>
        (! kpi.bits || currentProjectHasUsages(kpi.bits))
        && (! kpi.exceptBits || ! currentProjectHasUsages(kpi.exceptBits))
        && (! kpi.requiresSubscription || subscriptionShown)
        && ! (kpi.hideWhenSubscription && subscriptionShown)
        && ! (kpi.hideWhenPaidOnly && paidOnly)
        && ! (PAGE_VIEW_KPIS.includes(kpi.key) && ! pageViewsVisible && ! pageViewsNeverTracked)
        && ! (cohortActive && COHORT_NA_KPIS.includes(kpi.key));

    const chartDefs = [
        {
            name: 'Acquisition',
            order: 1,
            group: [
                {key: 'pd_visitor', formatter: 'number', bits: ['visitors'], indicatorCallback: () => statsSum.pd_visitor},
                {key: 'pd_reg', formatter: 'number', bits: ['users'], indicatorCallback: () => statsSum.pd_reg},
                {key: 'pd_cr', formatter: 'percent', bits: ['users', 'visitors'], indicatorCallback: () => safeDevision(statsSum.pd_reg, statsSum.pd_visitor) * 100},
                {key: 'pd_view', formatter: 'number', bits: ['visitors'], indicatorCallback: () => statsSum.pd_view},
                {key: 'pd_vpv', formatter: 'number', decimals: 1, bits: ['visitors'], indicatorCallback: () => safeDevision(statsSum.pd_view, statsSum.pd_viewer)},
                {key: 'pd_bounce_rate', formatter: 'percent', bits: ['visitors'], indicatorCallback: () => safeDevision(statsSum.pd_bounce, statsSum.pd_viewer) * 100},
            ],
        },
        {
            // The per-head revenue family (ARP*), grouped by metric kind so the detail
            // menu lists every "average revenue per X" together. With recurring revenue,
            // total Net lives in the MRR "Revenue" chart and this section is purely ARP*;
            // without subscriptions it also carries the project's Net revenue.
            name: subscriptionShown ? 'Average Revenue' : 'Revenue',
            order: 4,
            group: [
                {key: 'pd_net', formatter: 'currency', bits: ['payments'], hideWhenSubscription: true, tooltipExtra: [{key: 'pd_gross', label: 'Gross'}, {key: 'pd_fee', label: 'Fee'}], indicatorCallback: () => statsSum.pd_net},
                {key: 'lt_arpv', formatter: 'currency', decimals: 3, bits: ['payments', 'visitors'], exceptBits: ['users'], indicatorCallback: () => lastStat('lt_arpv')},
                // Revenue per visitor is tiny, so 2 decimals lose all granularity; show 3.
                {key: 'lt_arpv', formatter: 'currency', decimals: 3, bits: ['payments', 'visitors', 'users'], indicatorCallback: () => lastStat('lt_arpv')},
                {key: 'arps', formatter: 'currency', bits: ['payments', 'users'], requiresSubscription: true, indicatorCallback: () => lastStat('arps')},
                {key: 'lt_arpu', formatter: 'currency', bits: ['payments', 'users'], indicatorCallback: () => lastStat('lt_arpu')},
                {key: 'lt_arppu', formatter: 'currency', bits: ['payments', 'users'], hideWhenPaidOnly: true, indicatorCallback: () => lastStat('lt_arppu')},
                {key: 'pd_arpdau', formatter: 'currency', bits: ['users', 'payments'], indicatorCallback: () => lastStat('pd_arpdau')},
            ],
        },
        {
            name: 'Activity',
            order: 3,
            group: [
                // Projects without payments have no revenue chart, so Activity becomes their second chart.
                {key: 'pd_dau', formatter: 'number', bits: ['users'], exceptBits: ['payments'], indicatorCallback: () => lastStat('pd_dau')},
                {key: 'pd_nau', formatter: 'number', bits: ['users'], exceptBits: ['payments'], indicatorCallback: () => statsSum.pd_nau},
                {key: 'lt_reg2dau', formatter: 'percent', bits: ['users'], exceptBits: ['payments'], indicatorCallback: () => lastStat('lt_reg2dau')},
                {key: 'pd_dau', formatter: 'number', bits: ['users', 'payments'], indicatorCallback: () => lastStat('pd_dau')},
                {key: 'pd_dapu', formatter: 'number', bits: ['users', 'payments'], hideWhenPaidOnly: true, indicatorCallback: () => lastStat('pd_dapu')},
                {key: 'lt_pu', formatter: 'number', bits: ['users', 'payments'], hideWhenPaidOnly: true, indicatorCallback: () => lastStat('lt_pu')},
            ],
        },
        {
            name: 'Growth',
            order: 5,
            group: [
                {key: 'pd_nau', formatter: 'number', bits: ['users', 'payments'], indicatorCallback: () => statsSum.pd_nau},
                {key: 'pd_napu', formatter: 'number', bits: ['users', 'payments'], hideWhenPaidOnly: true, indicatorCallback: () => statsSum.pd_napu},
            ],
        },
        {
            name: 'Funnel & Depth',
            order: 6,
            group: [
                {key: 'lt_reg2dau', formatter: 'percent', bits: ['users', 'payments'], indicatorCallback: () => lastStat('lt_reg2dau')},
                {key: 'lt_reg2pu', formatter: 'percent', bits: ['users', 'payments'], hideWhenPaidOnly: true, indicatorCallback: () => lastStat('lt_reg2pu')},
            ],
        },
    ];

    const charts = chartDefs
        .map(def => ({...def, group: def.group.filter(kpiVisible)}))
        .filter(chart => chart.group.length > 0);

    charts.forEach(function(chartGroup) {
        let info = ``;

        chartGroup.group.forEach(function(group) {
            let kpiDef = kpiDefs[group.key];

            let title = (kpiDef.long) ? `${kpiDef.long} (${kpiDef.short})`: `${kpiDef.short}` ;
            info += `<div>
                        <div class="font-bold text-green-300">${title}</div>
                        <div>${kpiDef.description}</div>
                     </div>`;

            group.title = kpiDef.short;

            if (kpiDef.small) {
                group.titleSmall = kpiDef.small;
            }
        });

        chartGroup.info = info;
        chartGroup.data = extractStatsData4Chart(chartGroup.group);

        if (chartGroup.data.categories.length === 1) {
            chartGroup.data.categories[1] = chartGroup.data.categories[0];

            chartGroup.data.series.forEach(function(serie) {
                let value = serie.data[0];
                serie.data[0] = 0;
                serie.data[1] = value;

                if (serie.tooltipExtra) {
                    serie.tooltipExtra.forEach(t => { t.data[1] = t.data[0]; });
                }
            });
        }
    });

    // Pushed after the loop above so its pre-built snapshot data is not overwritten
    // by extractStatsData4Chart (which reads the period-summed teamStats.stats).
    if (currentProjectHasUsages(['subscription']) && ctx.mrrSeries?.length) {
        charts.push(buildMrrChart(ctx.mrrSeries));
    }

    if (ctx.engagementSeries?.length) {
        charts.push(buildFlatSeriesChart('Engagement', 7, ctx.engagementSeries, [
            {key: 'stickiness', formatter: 'percent'},
            {key: 'user_churn', formatter: 'percent'},
        ]));
    }

    if (ctx.revenueChurn?.length) {
        charts.push(buildFlatSeriesChart('Churn & efficiency', 8, ctx.revenueChurn, [
            {key: 'gross_churn', dataKey: 'gross_churn_rate', formatter: 'percent'},
            {key: 'net_churn', dataKey: 'net_churn_rate', formatter: 'percent'},
            {key: 'quick_ratio', formatter: 'number', decimals: 2},
        ]));
    }

    // Only present on the metric detail route (folded into Acquisition below). Deliberately
    // not added to OVERVIEW_DEFS, so it never enters the curated 3x3 dashboard overview.
    if (ctx.timeToConvertSeries?.length) {
        charts.push(buildFlatSeriesChart('Time to convert', 9, ctx.timeToConvertSeries, [
            {key: 'time_to_convert', formatter: 'number', decimals: 1, suffix: 'days'},
        ]));
    }

    charts.sort((a, b) => a.order - b.order);

    // Per-KPI view: flatten the section series into individual KPIs so the dashboard
    // overview and the metric detail menu share one source of truth.
    const currentPeriodLabel = formatDate(page.props.rangeCurrent, ctx.filters.range_type);
    const kpiByKey = {};

    const sections = charts.map((chart) => {
        const hasCurrent = chart.data.categories.at(-1) === currentPeriodLabel;

        const kpis = chart.data.series.map((serie) => {
            const kpiDef = kpiDefs[serie.key] ?? {};
            const title = kpiDef.long ? `${kpiDef.long} (${kpiDef.short})` : (kpiDef.short ?? serie.key);
            const pageViewsMissing = pageViewsNeverTracked && PAGE_VIEW_KPIS.includes(serie.key);

            const kpi = {
                key: serie.key,
                title: serie.name,
                titleLong: kpiDef.long ?? kpiDef.short ?? serie.key,
                titleSmall: serie.nameSmall,
                formatter: serie.formatter,
                decimals: serie.decimals ?? null,
                suffix: serie.suffix ?? null,
                serie,
                categories: chart.data.categories,
                indicator: pageViewsMissing ? null : serie.indicator,
                delta: pageViewsMissing ? 0 : computeDelta(serie, hasCurrent),
                pageViewsMissing,
                lowerIsBetter: kpiDef.lowerIsBetter ?? false,
                info: `<div><div class="font-bold text-green-300">${title}</div><div>${kpiDef.description ?? ''}</div></div>`,
                infoTitle: kpiDef.long ?? kpiDef.short ?? serie.key,
                infoText: kpiDef.description ?? '',
                isMrr: MRR_KPI_KEYS.includes(serie.key),
                breakdown: breakdownFor(serie.key),
                sectionName: chart.name,
            };

            kpiByKey[serie.key] = kpi;

            return kpi;
        });

        return {name: chart.name, order: chart.order, info: chart.info, categories: chart.data.categories, kpis};
    });

    // Fold the Engagement KPIs into Activity since they read as the same family in the
    // metric detail sidebar.
    const activitySection = sections.find((s) => s.name === 'Activity');
    const engagementSection = sections.find((s) => s.name === 'Engagement');

    if (activitySection && engagementSection) {
        activitySection.name = 'Activity & Engagement';
        activitySection.kpis.push(...engagementSection.kpis);
        sections.splice(sections.indexOf(engagementSection), 1);
    }

    // Time to convert reads as an acquisition-funnel metric (alongside CR), so fold its
    // standalone section into Acquisition in the metric detail sidebar.
    const acquisitionSection = sections.find((s) => s.name === 'Acquisition');
    const timeToConvertSection = sections.find((s) => s.name === 'Time to convert');

    if (acquisitionSection && timeToConvertSection) {
        acquisitionSection.kpis.push(...timeToConvertSection.kpis);
        sections.splice(sections.indexOf(timeToConvertSection), 1);
    }

    // Curated overview: pick the first OVERVIEW_SECTION_SIZE present KPIs per section.
    const overviewSections = OVERVIEW_DEFS
        .map((def) => ({
            name: def.name,
            kpis: def.keys.map((key) => kpiByKey[key]).filter(Boolean).slice(0, OVERVIEW_SECTION_SIZE),
        }))
        .filter((section) => section.kpis.length > 0);

    const findKpi = (key) => kpiByKey[key] ?? null;

    return {
        sections,
        overviewSections,
        kpiByKey,
        findKpi,
        cohortActive,
        cohortStartsAfterPeriod,
        showsRegistrations,
    };
}
