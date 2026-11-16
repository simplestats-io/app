<script setup>
import {computed, onMounted, ref} from 'vue';
import {Link, usePage} from "@inertiajs/vue3";
import axios from 'axios';
import StatsGroupTable from "./StatsGroupTable.vue";
import SegmentedToggle from "@/Components/SegmentedToggle.vue";
import {useUsage} from "@/Composables/useUsage.js";
import InfoIcon from 'bootstrap-icons/icons/info-circle.svg?component';

const lifetimeOptions = [
    {value: false, label: 'Time range'},
    {value: true, label: 'Lifetime'},
];

const lifetimeHint = '<strong>Time range</strong> counts entities active in the selected period. <strong>Lifetime</strong> ignores the date range and shows the current value of every entity plus its all-time revenue. Active filters apply to both.';

const props = defineProps({
    filters: Object,
    propertyDimensions: Array,
    propertyNameSelected: [Number, String],
    lifetimeSelected: Boolean,
    currentProject: Object,
    usageBits: Object,
    baseRoute: {type: String, default: 'dashboard'},
    routeParams: {type: Object, default: () => ({})},
    // Metric detail view: highlight + default-sort by the active KPI when it maps to
    // one of this group's columns. The property SQL has no ratio/MRR columns, so other
    // primaries (arpv, mrr, ...) are ignored and the default 'reg' sort stays.
    breakdownPrimary: {type: String, default: null},
});

const data = ref([]);
const dataPrevious = ref([]);
const loading = ref(true);
const lifetime = ref(props.lifetimeSelected);

const {currentProjectHasUsages} = useUsage(props.currentProject, props.usageBits);

const filters = Object.assign({}, props.filters);
const preselectedDimension = props.propertyDimensions.find((dimension) => dimension.id === Number(props.propertyNameSelected));
const selectedNameId = ref(preselectedDimension?.id ?? props.propertyDimensions[0]?.id);

// grouping by a custom property is just another stats_type on the shared
// grouped endpoint, the dimension itself travels via property_name_id
filters.stats_type = 'property';
filters.property_name_id = selectedNameId.value;
filters.lifetime = lifetime.value ? 1 : undefined;

const propertyColumnKeys = ['visitors', 'reg', 'cr', 'net'];
const curatedPrimary = props.breakdownPrimary && propertyColumnKeys.includes(props.breakdownPrimary) ? props.breakdownPrimary : null;

if (! filters.stats_sort) {
    filters.stats_sort = curatedPrimary ?? 'reg';
}

const fetchData = async () => {
    try {
        const response = await axios(route('stats.grouped', filters));

        data.value = response.data.data ?? [];
        dataPrevious.value = response.data.data_previous ?? [];
    } catch (error) {
        console.error('Error fetching property data:', error);
    } finally {
        loading.value = false;
    }
};

// Properties are mostly user-bound: the Regs column always shows, but the
// Visitors (and CR) column is hidden when the property has no visitor data.
const hasVisitorData = computed(() => data.value.some((item) => Number(item.visitors) > 0));

const showVisitors = computed(() => currentProjectHasUsages(['visitors']) && hasVisitorData.value);
const showCr = computed(() => showVisitors.value && currentProjectHasUsages(['users', 'visitors']));

const pageViewsVisible = !! usePage().props.pageViews?.visible;
const showViews = computed(() => pageViewsVisible && showVisitors.value);

const columns = computed(() => [
    {key: 'visitors', label: 'Visitors', labelSmall: 'Vis.', format: 'number', width: '13%', visible: showVisitors.value},
    {key: 'views', label: 'Views', format: 'optionalNumber', width: '11%', visible: showViews.value, tooltip: {
        title: 'Page Views',
        text: 'Pages viewed by the visitors with this value.',
    }},
    {key: 'reg', label: 'Regs', format: 'number', width: '13%', visible: currentProjectHasUsages(['users'])},
    {key: 'cr', label: 'Conv.', labelSmall: 'CR', format: 'percent', width: '13%', visible: showCr.value},
    {key: 'net', label: 'Revenue', labelSmall: 'Rev.', format: 'currency', width: '15%', visible: currentProjectHasUsages(['payments'])},
]);

const changeDimension = (nameId) => {
    selectedNameId.value = nameId;
    filters.property_name_id = nameId;
    filters.group_type = 'property';
    filters.limit = undefined;
    loading.value = true;
    fetchData();
};

const changeSort = (sort) => {
    filters.stats_sort = sort;
    loading.value = true;
    fetchData();
};

const setLifetime = (value) => {
    if (lifetime.value === value) {
        return;
    }

    lifetime.value = value;
    filters.lifetime = value ? 1 : undefined;
    filters.limit = undefined;
    loading.value = true;
    fetchData();

    axios.post(route('user-settings.property-lifetime'), {
        project_id: filters.project_id,
        lifetime: value,
    });
};

const loadMore = () => {
    filters.limit = (filters.limit ?? 0) + 100;
    fetchData();
};

const calculatePercentage = (current) => {
    let sort = filters.stats_sort === 'name' ? 'reg' : filters.stats_sort;

    // fall back when the sorted metric has no data at all,
    // e.g. a visitor-only property with the default "reg" sort
    if (! data.value.some((item) => Number(item[sort]) > 0)) {
        sort = hasVisitorData.value ? 'visitors' : 'reg';
    }

    const highest = data.value.reduce((max, item) => Number(item[sort]) > Number(max[sort]) ? item : max, data.value[0])[sort] ?? 0;

    return highest > 0 ? Math.floor((Number(current[sort]) / Number(highest)) * 100) : 0;
};

// the dashboard filter link must not inherit the grouping params
const dashboardLink = (item) => {
    const {stats_type, property_name_id, ...linkFilters} = filters;

    return route(props.baseRoute, {...props.routeParams, ...linkFilters, property_value: item.type_id});
};

onMounted(fetchData);
</script>

<template>
    <StatsGroupTable
        title="Custom Properties"
        :loading="loading"
        :data="data"
        :data-previous="dataPrevious"
        :columns="columns"
        :sort="filters.stats_sort"
        :highlight-column="curatedPrimary"
        name-label="Value"
        name-width="46%"
        :percentage="calculatePercentage"
        :empty-text="lifetime ? 'No data for this property yet.' : 'No data for this property in the selected period.'"
        :can-load-more="data.length >= 5 && ! filters.limit"
        @sort="changeSort"
        @load-more="loadMore"
    >
        <template #tabs>
            <div class="flex flex-wrap">
                <button v-for="dimension in propertyDimensions" :key="dimension.id"
                        class="flex pb-2 px-2 font-medium text-gray-500 dark:text-dark-300 hover:text-indigo-500 border-b"
                        :class="{'text-indigo-600 border-b-indigo-600': selectedNameId === dimension.id}"
                        @click="changeDimension(dimension.id)"
                >
                    {{ dimension.name }}
                </button>
            </div>
        </template>
        <template #footer-right>
            <div class="flex items-center gap-1.5">
                <div v-tooltip.left="{ content: `<div class='max-w-64 p-2 text-sm'>${lifetimeHint}</div>`, html: true }" class="text-gray-400">
                    <InfoIcon class="size-4" />
                </div>
                <SegmentedToggle :model-value="lifetime" :options="lifetimeOptions" @update:model-value="setLifetime" />
            </div>
        </template>
        <template #name-cell="{item}">
            <Link class="group truncate" :class="{'text-indigo-600 dark:text-indigo-400 underline': Number(filters.property_value) === item.type_id}" preserve-scroll :href="dashboardLink(item)">
                <span class="group-hover:underline ml-2" v-tooltip="{ content: item.name, delay: 2000 }">{{ item.name }}</span>
            </Link>
        </template>
    </StatsGroupTable>
</template>
