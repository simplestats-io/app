<script setup>
import StatsSmallChart from "@/Pages/Dashboard/Partials/Charts/StatsSmallChart.vue";
import CampaignKpiBar from "@/Pages/Campaigns/Partials/CampaignKpiBar.vue";
import {useChartDefinitions} from "@/Composables/useChartDefinitions.js";

const props = defineProps({
    filters: Object,
    teamStats: Object,
    currentProject: Object,
    usageBits: Object,
    flagBits: Object,
    mrrSeries: {type: Array, default: null},
    mrrSeriesPrev: {type: Array, default: null},
    campaignSummary: {type: Object, default: null},
});

const {
    overviewSections,
    cohortActive,
    cohortStartsAfterPeriod,
    showsRegistrations,
} = useChartDefinitions({
    filters: props.filters,
    teamStats: props.teamStats,
    currentProject: props.currentProject,
    usageBits: props.usageBits,
    flagBits: props.flagBits,
    mrrSeries: props.mrrSeries,
    mrrSeriesPrev: props.mrrSeriesPrev,
});

// Columns follow the section's chart count so a section always fills its row (a
// lone Visitors chart goes full width instead of leaving two empty columns).
// Full class strings, not interpolation, so Tailwind's JIT keeps them.
const gridColsClass = (count) => ({
    1: 'grid-cols-1',
    2: 'grid-cols-1 sm:grid-cols-2',
    3: 'grid-cols-1 lg:grid-cols-3',
}[Math.min(count, 3)]);

const ROW_SIZE = 3;

const colSpanClass = {
    1: 'lg:col-span-1',
    2: 'lg:col-span-2',
};

// A shared row is as wide as its charts, so two lone charts (1+1) split the row
// instead of leaving an empty third column.
const sharedRowClass = (row) => ({
    2: 'grid grid-cols-1 gap-x-4 gap-y-6 lg:grid-cols-2',
    3: 'grid grid-cols-1 gap-x-4 gap-y-6 lg:grid-cols-3',
}[row.reduce((sum, section) => sum + section.kpis.length, 0)]);

// Consecutive sections share a row while their charts fit into ROW_SIZE columns, so
// e.g. a lone Registrations chart (no visitors tracked) sits next to Revenue instead
// of spanning the full width. Sections that fill a row on their own stay unchanged.
const overviewRows = overviewSections.reduce((rows, section) => {
    const row = rows.at(-1);
    const used = row ? row.reduce((sum, s) => sum + s.kpis.length, 0) : ROW_SIZE;

    if (used + section.kpis.length <= ROW_SIZE) {
        row.push(section);
    } else {
        rows.push([section]);
    }

    return rows;
}, []);
</script>

<template>
    <div class="space-y-6">
        <CampaignKpiBar v-if="campaignSummary" :summary="campaignSummary" :showsRegistrations="showsRegistrations" />
        <p v-else-if="filters.campaign" class="text-sm text-gray-500 dark:text-dark-400">
            Spend-based campaign KPIs (ROAS, CAC, net profit) only apply to the full campaign. Clear the other active filters to see them.
        </p>
        <p v-if="cohortActive" class="text-sm text-gray-500 dark:text-dark-400">
            Showing only the selected registration cohort. Visitor-based metrics (Visitors, CR, ARPV) are not applicable and are hidden.
        </p>
        <p v-if="cohortStartsAfterPeriod" class="text-sm text-amber-600 dark:text-amber-400">
            The selected period ends before this cohort signed up, so there is nothing to show. Widen the period to cover the time since their registration.
        </p>

        <div
            v-for="(row, rowIndex) in overviewRows"
            :key="row.map((section) => section.name).join('|')"
            :class="row.length > 1 ? sharedRowClass(row) : null"
        >
            <section
                v-for="(section, index) in row"
                :key="section.name"
                class="min-w-0 space-y-3"
                :class="row.length > 1 ? colSpanClass[section.kpis.length] : null"
                :data-tour="rowIndex === 0 && index === 0 ? 'chart-info' : undefined"
            >
                <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-dark-400">
                    {{ section.name }}
                </h2>
                <div class="grid gap-4" :class="gridColsClass(section.kpis.length)">
                    <StatsSmallChart
                        v-for="kpi in section.kpis"
                        :key="kpi.key"
                        :kpi="kpi"
                        :filters="filters"
                    />
                </div>
            </section>
        </div>
    </div>
</template>
