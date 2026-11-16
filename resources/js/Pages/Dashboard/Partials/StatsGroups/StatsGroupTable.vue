<script setup>
import {computed, ref, toRef, useSlots} from 'vue';
import {formatCurrency, formatNumber, formatPercent} from "@/Helpers/NumberHelpers.js";
import {usePage} from "@inertiajs/vue3";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import {useStatsComparison} from "@/Composables/useStatsComparison.js";
import {infoTooltipContent} from "@/Helpers/TooltipHelpers.js";
import ChevronDownIcon from 'bootstrap-icons/icons/chevron-down.svg?component';

/**
 * Generic grouped stats table: sortable header, gradient bars, comparison
 * values and the "Load more" footer. The consumers (StatsGroup,
 * StatsCustomPropertyGroup) only provide the column config, the tabs and
 * the content of the name cell.
 */
const props = defineProps({
    title: String,
    loading: Boolean,
    data: Array,
    dataPrevious: Array,
    // [{key, label, labelSmall?, format: 'number'|'optionalNumber'|'percent'|'currency'|'days', width, visible?}]
    columns: Array,
    sort: String,
    // key of the column to visually emphasise (the metric detail view's active KPI)
    highlightColumn: String,
    nameLabel: String,
    nameWidth: String,
    // (item) => 0-100, drives the gradient bar of the name cell
    percentage: Function,
    emptyText: String,
    canLoadMore: Boolean,
});

const emit = defineEmits(['sort', 'load-more']);

const page = usePage();
const slots = useSlots();

const {comparisonChange, comparisonClass, formatComparison} = useStatsComparison(toRef(props, 'dataPrevious'));

const showFooter = computed(() => props.canLoadMore || !! slots['footer-left'] || !! slots['footer-right']);

const visibleColumns = computed(() => props.columns.filter((column) => column.visible !== false));

// Bold the active metric's column only on the metric detail view (highlightColumn);
// the overview breakdown stays calm and just marks the sort in the header.
const emphasizedColumn = computed(() => props.highlightColumn);

// Column tooltips ({title, text}) render in the shared InfoTooltip look
// (width-capped, green title) instead of an unbounded plain-string tooltip.
const headerTooltip = (column) => column.tooltip
    ? {content: infoTooltipContent(column.tooltip), html: true}
    : null;

const formatters = {
    number: (value) => formatNumber(Number(value)),
    // page views: a row whose visitors reported no view is unknown, not 0
    optionalNumber: (value, column) => value
        ? formatNumber(Number(value), column.decimals !== undefined ? {maximumFractionDigits: column.decimals} : undefined)
        : '-',
    // null = not observable yet (censored retention checkpoints), never 0%
    percent: (value, column) => {
        if (value === null || value === undefined) {
            return '-';
        }

        const options = column.decimals !== undefined
            ? {minimumFractionDigits: column.decimals, maximumFractionDigits: column.decimals}
            : undefined;

        return formatPercent(Number(value), options);
    },
    currency: (value) => formatCurrency(value, {}, page.props.teamCurrency),
    days: (value) => value !== null && Number.isFinite(Number(value))
        ? `${formatNumber(Number(value), {maximumFractionDigits: 1})} days`
        : '-',
};

const formatValue = (column, item) => formatters[column.format](item[column.key], column);

const isDarkMode = ref(document.documentElement.classList.contains('dark'));
const gradientColor = (isDarkMode.value) ? '46, 89, 113' : '220, 236, 254';
</script>

<template>
    <div class="@container flex flex-col flex-grow min-h-96 overflow-x-auto">
        <div v-if="loading" class="flex items-center justify-center h-96">
            <div>
                <svg class="text-gray-300 animate-spin -ml-1 mr-3 h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
        </div>
        <div v-else class="flex flex-col flex-grow p-4 text-sm @md:text-base">
            <div class="flex flex-col @lg:flex-row justify-between mb-4">
                <div class="font-bold text-center mb-3 @lg:mb-0">
                    {{ title }}
                </div>
                <slot name="tabs" />
            </div>
            <div class="overflow-y-auto" style="max-height: 300px;">
                <table class="w-full table-fixed">
                    <tbody>
                        <tr class="sticky bg-white dark:bg-dark-800 top-0 font-medium text-gray-600 dark:text-dark-300">
                            <td v-for="column in [{key: 'name', label: nameLabel, width: nameWidth}, ...visibleColumns]" :key="column.key"
                                :style="`width: ${column.width};`" :class="column.key === 'name' ? 'py-1.5' : 'p-1.5 text-right'"
                            >
                                <button class="whitespace-nowrap" :class="[sort === column.key ? 'text-indigo-600' : '', column.key === emphasizedColumn ? 'font-semibold' : '']" v-tooltip="headerTooltip(column)" @click="emit('sort', column.key)">
                                    <span v-if="column.labelSmall" class="inline-block @2xl:hidden">{{ column.labelSmall }}</span>
                                    <span :class="column.labelSmall ? 'hidden @2xl:inline-block' : ''">{{ column.label }}</span>
                                    <ChevronDownIcon v-if="sort === column.key" class="size-4 hidden @md:inline-block" />
                                </button>
                            </td>
                        </tr>
                        <tr v-for="item in data" :key="item.type_id ?? item.name">
                            <td class="py-1.5">
                                <div class="flex items-center h-9 w-full px-2 py-1.5 rounded-l"
                                     :style="'background: linear-gradient(to right, rgba('+gradientColor+', 1) '+percentage(item)+'%, rgba('+gradientColor+', 0) '+percentage(item)+'%);'"
                                >
                                    <slot name="name-cell" :item="item" />
                                </div>
                            </td>
                            <td v-for="column in visibleColumns" :key="column.key" class="p-1.5 text-right">
                                <span :class="column.key === emphasizedColumn ? 'font-semibold text-gray-900 dark:text-white' : ''">{{ formatValue(column, item) }}</span>
                                <span v-if="comparisonChange(item, column.key) !== null" class="block text-xs font-semibold leading-tight" :class="comparisonClass(comparisonChange(item, column.key))">{{ formatComparison(comparisonChange(item, column.key)) }}</span>
                            </td>
                        </tr>
                        <tr v-if="! data.length && emptyText">
                            <td :colspan="visibleColumns.length + 1" class="py-6 text-center text-gray-400">{{ emptyText }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="showFooter" class="flex items-center mt-auto pt-2">
                <div class="flex-1 flex justify-start">
                    <slot name="footer-left" />
                </div>
                <div class="flex-1 flex justify-center">
                    <SecondaryButton v-if="canLoadMore" @click="emit('load-more')">
                        Load more
                    </SecondaryButton>
                </div>
                <div class="flex-1 flex justify-end">
                    <slot name="footer-right" />
                </div>
            </div>
        </div>
    </div>
</template>
