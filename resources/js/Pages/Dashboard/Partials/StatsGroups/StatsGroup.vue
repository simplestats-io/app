<script setup>
import {computed, onMounted, ref} from 'vue';
import {Link, usePage} from "@inertiajs/vue3";
import {XAU, retentionCheckpointLabels} from "@/Helpers/StringHelpers.js";
import axios from 'axios';
import StatsIcon from "@/Components/StatsIcon.vue";
import StatsGroupTable from "./StatsGroupTable.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import {infoTooltipContent} from "@/Helpers/TooltipHelpers.js";
import {useUsage} from "@/Composables/useUsage.js";

const props = defineProps({
    filters: Object,
    statsGroup: Object,
    statsGroupType: String,
    statsGroupSelected: String,
    topReferers: Object,
    currentProject: Object,
    usageBits: Object,
    baseRoute: {type: String, default: 'dashboard'},
    routeParams: {type: Object, default: () => ({})},
    // Metric detail view: curated breakdown columns + the primary column to highlight/sort by.
    breakdownColumns: {type: Array, default: null},
    breakdownPrimary: {type: String, default: null},
});

const data = ref([]);
const dataPrevious = ref([]);
const loading = ref(true);

const {currentProjectHasUsages} = useUsage(props.currentProject, props.usageBits);

const filters = Object.assign({}, props.filters);

const pageViewsVisible = !! usePage().props.pageViews?.visible;
const searchConsole = usePage().props.searchConsole ?? {};
const searchConsoleVisible = !! searchConsole.visible;
const isEventGroup = props.statsGroupType === 'event';
const isPageGroup = props.statsGroupType === 'page';
const isTrackGroup = props.statsGroupType === 'track';
const TOP_PAGES = 'top_pages';
const KEYWORDS = 'keywords';

// Top Pages and Keywords are extra tabs, not stats types of their own.
const extraTabs = [
    ...(isPageGroup && pageViewsVisible ? [{type: TOP_PAGES, title: 'Top Pages'}] : []),
    ...(isTrackGroup && (searchConsoleVisible || searchConsole.suggest) ? [{type: KEYWORDS, title: 'Keywords'}] : []),
];
const tabs = [...props.statsGroup, ...extraTabs];

filters.stats_type = tabs.some((tab) => tab.type === props.statsGroupSelected)
    ? props.statsGroupSelected
    : tabs[0].type;

const statsType = ref(filters.stats_type);
const isTopPages = computed(() => statsType.value === TOP_PAGES);
const isKeywords = computed(() => statsType.value === KEYWORDS);
// Not connected yet, but Google already sends traffic: the tab invites to connect instead of loading data.
const isKeywordsHint = computed(() => isKeywords.value && ! searchConsoleVisible);
// Extra tabs have their own columns, sorts and a plain (non-filtering) name cell.
const isExtraTab = computed(() => isTopPages.value || isKeywords.value);
const extraTabDefaultSort = {[TOP_PAGES]: 'views', [KEYWORDS]: 'clicks'};

const xauLabel = XAU(filters.range_type);
const retentionLabels = retentionCheckpointLabels(filters.range_type, filters.range_amount);

// Every breakdown column a curated set can request, with its label/format and the
// tracking bits that gate it.
const columnRegistry = {
    visitors: {key: 'visitors', label: 'Visitors', labelSmall: 'Vis.', format: 'number', bits: ['visitors']},
    views:    {key: 'views', label: 'Views', format: 'optionalNumber', bits: ['visitors'], requiresPageViews: true},
    // sortWith: rank by volume, so a page with one visitor and 40 views does not lead.
    vpv:      {key: 'vpv', label: 'VPV', format: 'optionalNumber', decimals: 1, bits: ['visitors'], requiresPageViews: true, sortWith: 'visitors', tooltip: {
        title: 'Views per Visitor',
        text: 'Average number of pages a visitor in this row viewed.',
    }},
    bounce_rate: {key: 'bounce_rate', label: 'Bounce Rate', labelSmall: 'Bounce', format: 'percent', decimals: 0, bits: ['visitors'], requiresPageViews: true, sortWith: 'visitors', tooltip: {
        title: 'Bounce Rate',
        text: 'Share of visitors in this row who viewed only one page.',
    }},
    reg:      {key: 'reg', label: 'Regs', format: 'number', bits: ['users']},
    cr:       {key: 'cr', label: 'Conv.', labelSmall: 'CR', format: 'percent', bits: ['users', 'visitors']},
    dau:      {key: 'dau', label: xauLabel, format: 'number', bits: ['users']},
    net:      {key: 'net', label: 'Revenue', labelSmall: 'Rev.', format: 'currency', bits: ['payments']},
    arpv:     {key: 'arpv', label: 'ARPV', format: 'currency', bits: ['payments', 'visitors']},
    arpu:     {key: 'arpu', label: 'ARPU', format: 'currency', bits: ['payments', 'users']},
    arpdau:   {key: 'arpdau', label: 'ARPDAU', format: 'currency', bits: ['payments', 'users']},
    reg2dau:  {key: 'reg2dau', label: 'Reg→Active', labelSmall: 'R→A', format: 'percent', bits: ['users']},
    nau:      {key: 'nau', label: 'NAU', format: 'number', bits: ['users'], tooltip: {
        title: 'New Active Users',
        text: 'Users whose first return (their 2nd login) happened in the selected range, no matter when they signed up.',
    }},
    // whole percent: channel comparison doesn't need sub-percent precision, and the
    // often small censored denominators make decimals false precision anyway.
    // sortWith: rank by signup volume, not the rate itself, so a 3-user channel at
    // 100% doesn't outrank a 1000-user one (the rate stays the highlighted column).
    ret_c1:   {key: 'ret_c1', ...retentionLabels[0], format: 'percent', decimals: 0, bits: ['users'], sortWith: 'reg'},
    ret_c2:   {key: 'ret_c2', ...retentionLabels[1], format: 'percent', decimals: 0, bits: ['users'], sortWith: 'reg'},
    ret_c3:   {key: 'ret_c3', ...retentionLabels[2], format: 'percent', decimals: 0, bits: ['users'], sortWith: 'reg'},
    time_to_convert: {key: 'time_to_convert', label: 'Time to Conv.', labelSmall: 'TTC', format: 'days', bits: ['visitors', 'users']},
    mrr:      {key: 'mrr', label: 'MRR', format: 'currency', bits: ['subscription']},
    subscribers: {key: 'subscribers', label: 'Subs', format: 'number', bits: ['subscription']},
    arps:     {key: 'arps', label: 'ARPS', format: 'currency', bits: ['subscription']},
};

const viewsTooltip = {
    title: 'Page Views',
    text: {
        page: 'Pages viewed by visitors who started on this page. How often a page itself was viewed is shown in Top Pages.',
        event: 'Pages viewed by the visitors who triggered this event.',
    }[props.statsGroupType] ?? 'Number of pages the visitors in this row viewed.',
};

const showViews = pageViewsVisible && currentProjectHasUsages(['visitors']);

const defaultColumns = isEventGroup
    ? [
        {key: 'visitors', label: 'Visitors', format: 'number', width: '15%'},
        {key: 'triggered', label: 'Triggered', format: 'number', width: '15%'},
        {key: 'cr', label: 'Conv. Rate', labelSmall: 'CR', format: 'percent', width: '18%'},
        {key: 'views', label: 'Views', format: 'optionalNumber', width: '12%', visible: showViews, tooltip: viewsTooltip},
        {key: 'net', label: 'Revenue', labelSmall: 'Rev.', format: 'currency', width: '18%', visible: currentProjectHasUsages(['payments'])},
    ]
    : [
        {key: 'visitors', label: 'Visitors', labelSmall: 'Vis.', format: 'number', width: '12%', visible: currentProjectHasUsages(['visitors'])},
        {key: 'views', label: 'Views', format: 'optionalNumber', width: '10%', visible: showViews, tooltip: viewsTooltip},
        {key: 'reg', label: 'Regs', format: 'number', width: showViews ? '9%' : '10%', visible: currentProjectHasUsages(['users'])},
        {key: 'cr', label: 'Conv.', labelSmall: 'CR', format: 'percent', width: showViews ? '11%' : '12%', visible: currentProjectHasUsages(['users', 'visitors'])},
        {key: 'dau', label: xauLabel, format: 'number', width: '10%', visible: currentProjectHasUsages(['users'])},
        {key: 'net', label: 'Revenue', labelSmall: 'Rev.', format: 'currency', width: '12%', visible: currentProjectHasUsages(['payments'])},
    ];

const topPagesColumns = [
    {key: 'views', label: 'Views', format: 'number', width: '18%', tooltip: {title: 'Page Views', text: 'How often this page was viewed.'}},
    {key: 'visitors', label: 'Visitors', labelSmall: 'Vis.', format: 'number', width: '18%', tooltip: {title: 'Visitors', text: 'Number of visitors who viewed this page.'}},
];

const keywordsColumns = [
    {key: 'clicks', label: 'Clicks', format: 'number', width: '12%', tooltip: {title: 'Clicks', text: 'Clicks from Google search results to your site.'}},
    {key: 'impressions', label: 'Impressions', labelSmall: 'Impr.', format: 'number', width: '13%', tooltip: {title: 'Impressions', text: 'How often your site appeared in Google search results for this keyword.'}},
    {key: 'ctr', label: 'CTR', format: 'percent', decimals: 1, width: '10%', tooltip: {title: 'Click-through Rate', text: 'Share of impressions that led to a click.'}},
    {key: 'position', label: 'Position', labelSmall: 'Pos.', format: 'optionalNumber', decimals: 1, width: '11%', compare: false, tooltip: {title: 'Average Position', text: 'Average ranking in Google search results. Lower is better.'}},
    {key: 'est_net', label: 'Est. Revenue', labelSmall: 'Est. Rev.', format: 'currency', width: '14%', visible: currentProjectHasUsages(['payments']), tooltip: {
        title: 'Estimated Revenue',
        text: 'Revenue of the landing pages this keyword leads to, split by the keyword\'s share of their traffic. An estimate: Google does not tell which visitor searched for what.',
    }},
];

const tabTooltips = {
    // Only needed once both page tabs exist: they answer different questions.
    ...(pageViewsVisible ? {
        page_entry: {title: 'Entry Pages', text: 'The page visitors arrived on, with the registrations and revenue they brought.'},
        [TOP_PAGES]: {title: 'Top Pages', text: 'How often each page was viewed, no matter where visitors arrived.'},
    } : {}),
    [KEYWORDS]: {title: 'Google Keywords', text: 'Search keywords from Google Search Console, about two days delayed. Filters other than project, date range and entry page do not apply.'},
};

const tabTooltip = (tab) => tabTooltips[tab.type]
    ? {content: infoTooltipContent(tabTooltips[tab.type]), html: true}
    : null;

// Build the active KPI's curated column set, intersected with the project's tracking
// bits. If the highlighted primary doesn't survive that filter, fall back to defaults.
const buildCuratedColumns = () => {
    if (isEventGroup || ! props.breakdownColumns?.length) {
        return null;
    }

    const cols = props.breakdownColumns
        .map((key) => columnRegistry[key])
        .filter(Boolean)
        .filter((col) => ! col.bits || currentProjectHasUsages(col.bits))
        .filter((col) => ! col.requiresPageViews || pageViewsVisible);

    if (! cols.some((col) => col.key === props.breakdownPrimary)) {
        return null;
    }

    const width = `${Math.floor(56 / cols.length)}%`;

    return cols.map((col) => ({...col, width}));
};

const curatedColumns = buildCuratedColumns();
const columns = computed(() => {
    if (isTopPages.value) {
        return topPagesColumns;
    }

    if (isKeywords.value) {
        return isKeywordsHint.value ? [] : keywordsColumns;
    }

    return curatedColumns ?? defaultColumns;
});
const highlightColumn = computed(() => (curatedColumns && ! isExtraTab.value) ? props.breakdownPrimary : undefined);
const nameWidth = computed(() => {
    if (isTopPages.value) {
        return '64%';
    }

    if (isKeywords.value) {
        return currentProjectHasUsages(['payments']) ? '40%' : '54%';
    }

    if (isEventGroup) {
        return '34%';
    }

    return showViews ? '36%' : '44%';
});

// Rate columns opt out of sorting by themselves (sortWith, see registry): they rank by a
// volume column while staying the highlighted primary.
const primaryDef = curatedColumns ? columnRegistry[props.breakdownPrimary] : null;
const defaultCuratedSort = primaryDef?.sortWith && curatedColumns.some((col) => col.key === primaryDef.sortWith)
    ? primaryDef.sortWith
    : props.breakdownPrimary;

if (! filters.stats_sort) {
    filters.stats_sort = isEventGroup
        ? 'triggered'
        : (curatedColumns ? defaultCuratedSort : 'visitors');
}

const groupSort = filters.stats_sort;

if (isExtraTab.value) {
    filters.stats_sort = extraTabDefaultSort[statsType.value];
}

// The MRR columns are a point-in-time snapshot the backend only computes on request,
// so opt in whenever the curated set surfaces them for a recurring-revenue project.
if (curatedColumns
    && ['mrr', 'subscribers', 'arps'].includes(props.breakdownPrimary)
    && currentProjectHasUsages(['subscription'])
) {
    filters.metric = 'mrr';
}

// The per-dimension time-to-convert median is likewise opt-in (an extra grouped query).
if (curatedColumns
    && props.breakdownPrimary === 'time_to_convert'
    && currentProjectHasUsages(['visitors', 'users'])
) {
    filters.metric = 'time_to_convert';
}

// The retention checkpoint columns are opt-in as well. Checked against the curated
// set (not just the primary) because metric pages surface them as secondary
// companions (e.g. DAU); buildCuratedColumns already gated them on the users bit.
if (curatedColumns?.some((col) => ['ret_c1', 'ret_c2', 'ret_c3'].includes(col.key))) {
    filters.metric = 'retention';
}

// The per-dimension NAU count rides the same opt-in; backend-side 'nau' implies
// the retention columns too (a request carries only one metric value).
if (curatedColumns?.some((col) => col.key === 'nau')) {
    filters.metric = 'nau';
}

const fetchData = async () => {
    if (isKeywordsHint.value) {
        data.value = [];
        dataPrevious.value = [];
        loading.value = false;

        return;
    }

    try {
        const response = await axios(route('stats.grouped', filters));
        const payload = response.data;

        // backwards-compatible: API may return either {data, data_previous} or a plain array
        if (Array.isArray(payload)) {
            data.value = payload;
            dataPrevious.value = [];
        } else {
            data.value = payload.data ?? [];
            dataPrevious.value = payload.data_previous ?? [];
        }
    } catch (error) {
        console.error('Error fetching data:', error);
    } finally {
        loading.value = false;
    }
};

const group2Name = {
    track: 'Tracking',
    page: 'Pages',
    location: 'Locations',
    device: 'Devices',
    event: 'Custom Events',
};

const changeGroup = (type, statsGroupType) => {
    const wasExtraTab = isExtraTab.value;

    filters.stats_type = type;
    filters.group_type = statsGroupType;
    statsType.value = type;

    // Extra tabs share no sort columns with the regular ones.
    if (isExtraTab.value) {
        filters.stats_sort = extraTabDefaultSort[type];
    } else if (wasExtraTab) {
        filters.stats_sort = groupSort;
    }

    loading.value = true;
    fetchData();
};

const changeSort = (sort) => {
    filters.stats_sort = sort;
    loading.value = true;
    fetchData();
};

const loadMore = () => {
    filters.limit = (filters.limit ?? 0) + 100;
    fetchData();
};

const calculatePercentage = (current) => {
    const defaultSort = isEventGroup ? 'triggered' : (extraTabDefaultSort[statsType.value] ?? 'visitors');
    // Rates and positions make no sense as bar length, the bar follows the volume instead.
    const volumeOnly = ['name', 'ctr', 'position'].includes(filters.stats_sort);
    const sort = (volumeOnly || (isEventGroup && filters.stats_sort === 'visitors')) ? defaultSort : filters.stats_sort ;

    const highest = data.value.reduce((max, item) => item[sort] > max[sort] ? item : max, data.value[0])[sort];

    // Rate columns can be null (censored retention), and the max is 0/null when a whole
    // dimension is still censored: guard against NaN/Infinity gradient bars.
    if (! highest || current[sort] == null) {
        return 0;
    }

    return Math.floor((current[sort] / highest) * 100);
};

const statsTypeIcon =  (statsGroup, item) => {
    return statsGroup.filter((item) => item.type === filters.stats_type)[0]?.icon;
}

onMounted(fetchData);
</script>

<template>
    <StatsGroupTable
        :title="group2Name[statsGroupType]"
        :loading="loading"
        :data="data"
        :data-previous="dataPrevious"
        :columns="columns"
        :sort="filters.stats_sort"
        :highlight-column="highlightColumn"
        :name-label="isKeywordsHint ? '' : 'Name'"
        :name-width="nameWidth"
        :percentage="calculatePercentage"
        :empty-text="isEventGroup ? 'No custom events in the selected period.' : undefined"
        :can-load-more="data.length >= 5 && ! filters.limit"
        @sort="changeSort"
        @load-more="loadMore"
    >
        <template v-if="isKeywordsHint" #empty>
            <div class="max-w-sm mx-auto space-y-3 text-gray-600 dark:text-dark-300">
                <p class="font-medium text-gray-900 dark:text-white">Which Google searches bring these visitors?</p>
                <p class="text-sm">Connect Google Search Console to see the keywords behind your Google traffic, with clicks, ranking and estimated revenue.</p>
                <a v-if="searchConsole.canConnect" :href="searchConsole.connectUrl" class="inline-block">
                    <PrimaryButton type="button">Connect Search Console</PrimaryButton>
                </a>
                <p v-else class="text-sm">Ask an administrator or editor of your team to connect it.</p>
            </div>
        </template>
        <template #tabs>
            <div class="flex flex-wrap justify-center">
                <button v-for="stat in tabs" class="flex pb-2 px-2 font-medium text-gray-500 dark:text-dark-300 hover:text-indigo-500 border-b" :class="{'text-indigo-600 border-b-indigo-600': statsType === stat.type}" v-tooltip="tabTooltip(stat)" @click="changeGroup(stat.type, statsGroupType)">
                    {{ stat.title }}
                </button>
            </div>
        </template>
        <template #name-cell="{item}">
            <!-- direction: rtl; is that on small screens the page uris got truncated from left so that you can see the end of the uri with prio -->
            <span v-if="isTopPages" class="truncate" style="direction: rtl;">
                <span class="ml-2" v-tooltip="{ content: item.name, delay: 2000 }">{{ item.name }}</span>
            </span>
            <span v-else-if="isKeywords" class="truncate">
                <span class="ml-2" v-tooltip="{ content: item.name, delay: 2000 }">{{ item.name }}</span>
            </span>
            <Link v-else class="group truncate" :style="(statsGroupType === 'page') ? 'direction: rtl;' : '' " :class="{'text-indigo-600 dark:text-indigo-400 underline': filters[filters.stats_type] === item.type_id}" preserve-scroll :href="route(baseRoute, {...routeParams, ...filters, [filters.stats_type]: item.type_id ?? -1})">
                <StatsIcon v-if="statsGroupType !== 'page' && statsGroupType !== 'event'" :statsName="item.name" :statsType="filters.stats_type" :statsIconType="statsTypeIcon(statsGroup, item)" />
                <span class="group-hover:underline ml-2" v-tooltip="{ content: topReferers[item.name] ?? item.name, delay: 2000 }">{{ topReferers[item.name] ?? item.name }}</span>
            </Link>
        </template>
    </StatsGroupTable>
</template>
