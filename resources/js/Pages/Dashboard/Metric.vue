<script setup>
import {computed, onMounted, ref} from "vue";
import {Link, router} from "@inertiajs/vue3";
import AppLayout from '@/Layouts/AppLayout.vue';
import StatsFilter from "@/Pages/Dashboard/Partials/StatsFilter.vue";
import StatsGroups from "@/Pages/Dashboard/Partials/StatsGroups/StatsGroups.vue";
import StatsChartCanvas from "@/Pages/Dashboard/Partials/Charts/StatsChartCanvas.vue";
import PageViewsEmptyState from "@/Pages/Dashboard/Partials/Charts/PageViewsEmptyState.vue";
import HeadlineKpiTile from "@/Pages/Dashboard/Partials/HeadlineKpiTile.vue";
import MetricPlanBreakdown from "@/Pages/Dashboard/Partials/MetricPlanBreakdown.vue";
import {resolveChartSeries, useChartDefinitions} from "@/Composables/useChartDefinitions.js";
import SlidersIcon from 'bootstrap-icons/icons/sliders.svg?component';
import ChevronDownIcon from 'bootstrap-icons/icons/chevron-down.svg?component';

const props = defineProps({
    kpi: String,
    teamStats: Object,
    presets: Array,
    allPresets: Array,
    hiddenPresets: Array,
    customPresets: Array,
    filters: Object,
    statsGroups: Object,
    propertyDimensions: Array,
    hasCustomEvents: Boolean,
    statsGroupNames: Object,
    topReferers: Object,
    rangeTypes: Array,
    usageBits: Object,
    flagBits: Object,
    comparisonTypes: Object,
    mrr_series: {type: Array, default: null},
    mrr_series_prev: {type: Array, default: null},
    plan_breakdown: {type: Array, default: null},
    engagement_series: {type: Array, default: null},
    revenue_churn: {type: Array, default: null},
    time_to_convert_series: {type: Array, default: null},
});

const currentProject = props.teamStats.projects[props.filters.project_id] ?? props.teamStats.projects[0];

const {sections, findKpi} = useChartDefinitions({
    filters: props.filters,
    teamStats: props.teamStats,
    currentProject,
    usageBits: props.usageBits,
    flagBits: props.flagBits,
    mrrSeries: props.mrr_series,
    mrrSeriesPrev: props.mrr_series_prev,
    engagementSeries: props.engagement_series,
    revenueChurn: props.revenue_churn,
    timeToConvertSeries: props.time_to_convert_series,
});

const activeKpi = computed(() => findKpi(props.kpi));

// On small screens the full KPI list collapses behind a select-like trigger.
const metricListOpen = ref(false);

// A KPI key that the current team/preset does not surface (e.g. a stale link kept
// across a team switch) has no chart to show, so fall back to the dashboard.
const invalidKpi = ! activeKpi.value;

onMounted(() => {
    if (invalidKpi) {
        router.get(route('dashboard'), props.filters, {preserveScroll: true});
    }
});
</script>

<template>
    <AppLayout title="Dashboard" :fullscreen="true">
        <StatsFilter
            :teamStats="teamStats"
            :statsGroups="statsGroups"
            :statsGroupNames="statsGroupNames"
            :presets="presets"
            :allPresets="allPresets"
            :hiddenPresets="hiddenPresets"
            :customPresets="customPresets"
            :filters="filters"
            :rangeTypes="rangeTypes"
            :topReferers="topReferers"
            :usageBits="usageBits"
            :comparisonTypes="comparisonTypes"
            submitRoute="dashboard.metric"
            :submitRouteParams="{ kpi }"
        />

        <div v-if="activeKpi" class="grid grid-cols-1 lg:grid-cols-[14rem_minmax(0,1fr)] gap-4">
            <!-- Left themed menu -->
            <nav class="bg-white dark:bg-dark-800 border rounded-md p-2 h-fit lg:sticky lg:top-24">
                <Link :href="route('dashboard', filters)" class="block px-3 py-2 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                    &larr; Overview
                </Link>

                <button
                    type="button"
                    @click="metricListOpen = ! metricListOpen"
                    :aria-expanded="metricListOpen"
                    class="lg:hidden mt-1 w-full flex items-center justify-between gap-2 px-3 py-2 text-sm rounded-md border border-gray-200 dark:border-dark-700 text-gray-900 dark:text-white"
                >
                    <span class="truncate font-medium">{{ activeKpi.title }}</span>
                    <ChevronDownIcon class="size-4 shrink-0 text-gray-400 transition-transform" :class="{ 'rotate-180': metricListOpen }" />
                </button>

                <div class="lg:block" :class="metricListOpen ? 'block' : 'hidden'">
                    <div v-for="section in sections" :key="section.name" class="mt-2">
                        <div class="px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-dark-500">
                            {{ section.name }}
                        </div>
                        <Link
                            v-for="item in section.kpis"
                            :key="item.key"
                            :href="route('dashboard.metric', { kpi: item.key, ...filters })"
                            preserve-scroll
                            class="block px-3 py-1.5 text-sm rounded-md"
                            :class="item.key === kpi
                                ? 'bg-gray-100 dark:bg-dark-900/50 text-gray-950 dark:text-white font-medium'
                                : 'text-gray-700 dark:text-dark-300 hover:bg-gray-50 dark:hover:bg-dark-900/30'"
                        >
                            {{ item.title }}
                        </Link>
                    </div>
                </div>

                <div class="mt-3 pt-3 border-t border-gray-100 dark:border-dark-700">
                    <p class="px-3 pb-1 text-xs text-gray-400 dark:text-dark-500">
                        Missing a metric?
                    </p>
                    <Link
                        :href="currentProject.uuid ? route('projects.show', currentProject) : route('projects.index')"
                        class="flex items-center gap-2 px-3 py-1.5 text-sm rounded-md text-gray-600 dark:text-dark-300 hover:bg-gray-50 dark:hover:bg-dark-900/30 hover:text-gray-900 dark:hover:text-white"
                    >
                        <SlidersIcon class="size-4 shrink-0" />
                        <span>Configure tracking</span>
                    </Link>
                </div>
            </nav>

            <!-- Main: big chart + breakdown -->
            <div class="space-y-4 min-w-0">
                <div class="bg-white dark:bg-dark-800 border rounded-md">
                    <HeadlineKpiTile
                        bare
                        delta-inline
                        :label="activeKpi.titleLong"
                        :value="activeKpi.indicator"
                        :formatter="activeKpi.formatter"
                        :suffix="activeKpi.suffix"
                        :delta="activeKpi.delta"
                        :lowerIsBetter="activeKpi.lowerIsBetter"
                        :infoTitle="activeKpi.infoTitle"
                        :infoText="activeKpi.infoText"
                    />
                    <PageViewsEmptyState v-if="activeKpi.pageViewsMissing" :height="380" show-guide-link />
                    <StatsChartCanvas
                        v-else
                        :series="resolveChartSeries(activeKpi.serie)"
                        :categories="activeKpi.categories"
                        :formatter="activeKpi.formatter"
                        :decimals="activeKpi.decimals"
                        :filters="filters"
                        :height="380"
                        navigateRoute="dashboard.metric"
                        :navigateParams="{ kpi }"
                    />
                </div>

                <MetricPlanBreakdown v-if="activeKpi.isMrr" :planBreakdown="plan_breakdown" />
                <StatsGroups
                    v-if="! activeKpi.pageViewsMissing"
                    :filters="filters"
                    :statsGroups="statsGroups"
                    :propertyDimensions="propertyDimensions"
                    :hasCustomEvents="hasCustomEvents"
                    :topReferers="topReferers"
                    :currentProject="currentProject"
                    :usageBits="usageBits"
                    :breakdownColumns="activeKpi.breakdown?.columns"
                    :breakdownPrimary="activeKpi.breakdown?.primary"
                    :showCustomDimensions="false"
                    baseRoute="dashboard.metric"
                    :routeParams="{ kpi }"
                />
            </div>
        </div>
    </AppLayout>
</template>
