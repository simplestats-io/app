<script setup>
import {computed} from "vue";
import {Link} from "@inertiajs/vue3";
import {formatMap} from "@/Helpers/NumberHelpers.js";
import {resolveChartSeries} from "@/Composables/useChartDefinitions.js";
import StatsChartCanvas from "@/Pages/Dashboard/Partials/Charts/StatsChartCanvas.vue";
import PageViewsEmptyState from "@/Pages/Dashboard/Partials/Charts/PageViewsEmptyState.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import ArrowTrendingDownIcon from "bootstrap-icons/icons/arrow-down-right.svg?component";
import ArrowTrendingUpIcon from "bootstrap-icons/icons/arrow-up-right.svg?component";

const props = defineProps({
    kpi: Object,
    filters: Object,
});

const hasDelta = computed(() => props.kpi.delta !== 0);
// Color reflects whether the movement is good, the arrow keeps the actual direction.
const deltaIsGood = computed(() => props.kpi.lowerIsBetter ? props.kpi.delta < 0 : props.kpi.delta > 0);
const deltaClass = computed(() => hasDelta.value ? (deltaIsGood.value ? 'text-green-600' : 'text-red-400') : 'text-blue-400');
const deltaIcon = computed(() => hasDelta.value ? (props.kpi.delta > 0 ? ArrowTrendingUpIcon : ArrowTrendingDownIcon) : null);
const deltaFractionDigits = computed(() => Math.abs(props.kpi.delta) >= 10 ? 1 : 2);

const currencyFractionDigits = computed(() => {
    if (props.kpi.decimals != null) {
        return props.kpi.decimals;
    }

    return (props.kpi.indicator < 10000 || props.kpi.indicator >= 100000) ? 2 : 0;
});

const numberFractionDigits = computed(() => props.kpi.decimals ?? (props.kpi.indicator >= 1000 ? 2 : 0));
</script>

<template>
    <Link
        :href="route('dashboard.metric', { kpi: kpi.key, ...filters })"
        preserve-scroll
        class="block @container bg-white dark:bg-dark-800 border rounded-md hover:border-blue-400 transition-colors"
    >
        <div class="px-3 pt-3">
            <div>
                <div class="flex items-center gap-1 text-sm font-medium tracking-wider text-gray-700 dark:text-dark-400">
                    <span class="truncate">{{ kpi.title }}</span>
                    <InfoTooltip v-if="kpi.infoText" :title="kpi.infoTitle" :text="kpi.infoText" iconClass="size-4" />
                </div>
                <div class="mt-0.5 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        <span v-if="kpi.formatter === 'currency'">
                            {{ formatMap.currency(kpi.indicator, {minimumFractionDigits: currencyFractionDigits, maximumFractionDigits: currencyFractionDigits}, $page.props.teamCurrency) }}
                        </span>
                        <span v-else-if="kpi.formatter === 'percent'">
                            {{ formatMap.percent(kpi.indicator, {minimumFractionDigits: 2, maximumFractionDigits: 2}) }}
                        </span>
                        <span v-else>
                            {{ formatMap.number(kpi.indicator, {minimumFractionDigits: numberFractionDigits, maximumFractionDigits: numberFractionDigits}) }}
                        </span>
                    </div>
                    <div v-if="hasDelta" class="text-sm font-bold whitespace-nowrap" :class="deltaClass">
                        {{ formatMap.percent(kpi.delta, {minimumFractionDigits: deltaFractionDigits, maximumFractionDigits: deltaFractionDigits}) }}
                        <component v-if="deltaIcon" :is="deltaIcon" class="inline-block" :class="deltaClass" />
                    </div>
                </div>
            </div>
        </div>
        <PageViewsEmptyState v-if="kpi.pageViewsMissing" :height="140" />
        <StatsChartCanvas
            v-else
            :series="resolveChartSeries(kpi.serie)"
            :categories="kpi.categories"
            :formatter="kpi.formatter"
            :decimals="kpi.decimals"
            :filters="filters"
            :height="140"
            :clickable="false"
            :max-x-labels="4"
        />
    </Link>
</template>
