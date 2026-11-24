<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import CampaignFilter from "@/Pages/Campaigns/Partials/CampaignFilter.vue";
import CampaignModal from "@/Pages/Campaigns/Partials/CampaignModal.vue";
import SubscriptionPlanAlarm from "@/Pages/Dashboard/Partials/SubscriptionPlanAlarm.vue";
import ActionTable from "@/Components/ActionTable.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import DashboardIcon from "bootstrap-icons/icons/grid.svg?component";
import Wallet2Icon from "bootstrap-icons/icons/wallet2.svg?component";
import RetentionIcon from "bootstrap-icons/icons/activity.svg?component";
import ExclamationCircleIcon from "bootstrap-icons/icons/exclamation-circle.svg?component";
import {computed, ref} from "vue";
import {router, usePage} from "@inertiajs/vue3";
import {useUsage} from "@/Composables/useUsage.js";
import {formatMap} from "@/Helpers/NumberHelpers.js";
import {
    formatLtvCac,
    formatPayback,
    formatRoas as roas,
    ltvCacClass,
    paybackClass,
    profitClass,
    roasClass
} from "@/Helpers/CampaignKpiHelpers.js";
import {campaignMetricInfo as metricInfo} from "@/Data/CampaignMetricInfo.js";

const props = defineProps({
    teamStats: Object,
    presets: Array,
    filters: Object,
    rangeTypes: Array,
    subscriptionPlanAlarm: Array,
    usageBits: Object,
    campaigns: {type: Object, default: () => ({data: [], links: []})},
    projects: {type: Array, default: () => []},
    canCreateForAllProjects: Boolean,
});

const page = usePage();

const currentProject = props.teamStats.projects[props.filters.project_id] ?? props.teamStats.projects[0];
const {currentProjectHasUsages} = useUsage(currentProject, props.usageBits);

const showsRevenue = currentProjectHasUsages(['payments']);
const showsRegistrations = currentProjectHasUsages(['users']);
const showsSubscription = currentProjectHasUsages(['subscription']);
const showProjectColumn = props.projects.length > 1 && (props.filters.project_id ?? 0) === 0;
const offersAllProjects = props.canCreateForAllProjects && props.projects.length > 1;
const campaignProjectOptions = offersAllProjects ? [{uuid: 'all', name: 'All projects'}, ...props.projects] : props.projects;
const defaultProjectId = props.projects.find(project => project.uuid === props.filters.project_id)?.uuid
    ?? (offersAllProjects ? 'all' : props.projects[0]?.uuid);

const columnCount = 3 + (showProjectColumn ? 1 : 0) + (showsRevenue ? 3 : 0) + (showsRegistrations ? 1 : 0) + (showsSubscription ? 2 : 0);

const currency = (cents, code = page.props.teamCurrency) => formatMap.currency(cents, {notation: 'standard'}, code);
const rangeCurrency = (row, cents) => row.has_activity ? currency(cents) : '–';

// UTC because the backend sends plain dates, which `new Date()` parses as UTC midnight.
const dateFormatter = new Intl.DateTimeFormat('en-US', {year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC'});
const formatRuns = (row) => {
    if (!row.runs_from) {
        return 'No spend yet';
    }

    const from = dateFormatter.format(new Date(row.runs_from));

    return row.runs_from === row.runs_until ? from : `${from} – ${dateFormatter.format(new Date(row.runs_until))}`;
};

const showCampaignModal = ref(false);

const inactiveCount = computed(() => props.campaigns.data.filter(row => !row.has_activity).length);
const showInactive = ref(false);
const visibleRows = computed(() => [
    ...props.campaigns.data.filter(row => row.has_activity),
    ...(showInactive.value ? props.campaigns.data.filter(row => !row.has_activity) : []),
]);

const manageCampaign = (row) => {
    router.get(route('campaigns.show', {campaign: row.campaign_uuid, ...props.filters}));
};

// A campaign for all projects has no project uuid; 0 is the "All projects" scope.
const rowScope = (row) => row.project_uuid ?? 0;

const analyzeCampaign = (row) => {
    router.get(route('dashboard', {...props.filters, project_id: rowScope(row), campaign: row.campaign_uuid}));
};

// Only projects that track subscriptions have a revenue view to drill into.
const subscriptionBit = props.usageBits.subscription;
const tracksSubscription = (projectId) => !! ((props.teamStats.projects[projectId]?.usage ?? 0) & subscriptionBit);

const usersBit = props.usageBits.users;
const tracksUsers = (projectId) => !! ((props.teamStats.projects[projectId]?.usage ?? 0) & usersBit);

const analyzeCampaignRevenue = (row) => {
    router.get(route('revenue', {...props.filters, project_id: rowScope(row), campaign: row.campaign_uuid}));
};

const analyzeCampaignRetention = (row) => {
    router.get(route('retention', {...props.filters, project_id: rowScope(row), campaign: row.campaign_uuid}));
};
</script>

<template>
    <AppLayout title="Campaigns" :fullscreen="true">
        <div v-if="subscriptionPlanAlarm.length > 0" class="mb-6">
            <SubscriptionPlanAlarm :subscriptionPlanAlarm="subscriptionPlanAlarm" />
        </div>

        <div class="space-y-6">
            <div class="space-y-3">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">Campaigns</h1>
                    <CampaignFilter
                        :teamStats="teamStats"
                        :filters="filters"
                        :presets="presets"
                        :rangeTypes="rangeTypes"
                        submitRoute="campaigns"
                    />
                </div>
                <h2 class="max-w-3xl text-base text-gray-500 dark:text-dark-400">
                    Use your favorite ad portal for <span class="italic font-semibold">targeting</span>, SimpleStats for <span class="italic font-semibold">analysing</span>!
                </h2>
                <p class="max-w-3xl text-sm text-gray-500 dark:text-dark-400">
                    No tracking pixel, GDPR compliant and immune to ad blockers: enter what you spent on Meta, Google &amp; co. and see each campaign's real, server-side measured revenue, ROAS, CAC and net profit, the numbers your ad dashboards can't show you.
                </p>
            </div>

            <ActionTable :links="campaigns.links" maxWidth="max-w-none">
                <template #title>Campaign profitability</template>
                <template #description>Spend in the selected range versus everything the customers it acquired have paid so far. Click a campaign to manage its ad spend.</template>
                <template #aside>
                    <PrimaryButton class="shrink-0 whitespace-nowrap" @click="showCampaignModal = true">Track a campaign</PrimaryButton>
                </template>

                <template #content>
                    <tr class="text-left font-bold text-gray-700 dark:text-dark-300">
                        <th class="pb-4 pt-6 px-6">Campaign</th>
                        <th v-if="showProjectColumn" class="pb-4 pt-6 px-6">Project</th>
                        <th class="pb-4 pt-6 px-6">
                            <span class="inline-flex items-center gap-1">Runs <InfoTooltip :title="metricInfo.runs.title" :text="metricInfo.runs.text" /></span>
                        </th>
                        <th class="pb-4 pt-6 px-6 text-right">
                            <span class="inline-flex items-center justify-end gap-1">Spend <InfoTooltip :title="metricInfo.spend.title" :text="metricInfo.spend.text" /></span>
                        </th>
                        <th v-if="showsRevenue" class="pb-4 pt-6 px-6 text-right">
                            <span class="inline-flex items-center justify-end gap-1">Revenue <InfoTooltip :title="metricInfo.revenue.title" :text="metricInfo.revenue.text" /></span>
                        </th>
                        <th v-if="showsRevenue" class="pb-4 pt-6 px-6 text-right">
                            <span class="inline-flex items-center justify-end gap-1">Net profit <InfoTooltip :title="metricInfo.netProfit.title" :text="metricInfo.netProfit.text" /></span>
                        </th>
                        <th v-if="showsRevenue" class="pb-4 pt-6 px-6 text-right">
                            <span class="inline-flex items-center justify-end gap-1">ROAS <InfoTooltip :title="metricInfo.roas.title" :text="metricInfo.roas.text" /></span>
                        </th>
                        <th v-if="showsRegistrations" class="pb-4 pt-6 px-6 text-right">
                            <span class="inline-flex items-center justify-end gap-1">CAC <InfoTooltip :title="metricInfo.cac.title" :text="metricInfo.cac.text" /></span>
                        </th>
                        <th v-if="showsSubscription" class="pb-4 pt-6 px-6 text-right">
                            <span class="inline-flex items-center justify-end gap-1">LTV:CAC <InfoTooltip :title="metricInfo.ltvCac.title" :text="metricInfo.ltvCac.text" /></span>
                        </th>
                        <th v-if="showsSubscription" class="pb-4 pt-6 px-6 text-right">
                            <span class="inline-flex items-center justify-end gap-1">Payback <InfoTooltip :title="metricInfo.cacPayback.title" :text="metricInfo.cacPayback.text" /></span>
                        </th>
                        <th class="pb-4 pt-6 px-6"></th>
                    </tr>
                    <tr
                        v-for="row in visibleRows"
                        :key="row.campaign_uuid + '-' + row.name"
                        class="hover:bg-gray-50 dark:hover:bg-dark-700 text-gray-700 dark:text-dark-300"                    >
                        <td class="border-t px-6 py-4 font-medium">
                            <button type="button" class="text-left text-indigo-600 dark:text-indigo-400 hover:underline" v-tooltip="'Manage ad spend'" @click="manageCampaign(row)">
                                {{ row.name }}
                            </button>
                            <div v-if="!row.has_activity" class="mt-1 flex items-center gap-1 text-xs font-normal text-amber-600 dark:text-amber-400">
                                <ExclamationCircleIcon class="size-3.5 shrink-0" />
                                No activity in this range
                            </div>
                        </td>
                        <td v-if="showProjectColumn" class="border-t px-6 py-4 text-gray-500 dark:text-dark-400">{{ row.project_name }}</td>
                        <td class="border-t px-6 py-4 whitespace-nowrap text-gray-500 dark:text-dark-400">{{ formatRuns(row) }}</td>
                        <td class="border-t px-6 py-4 text-right tabular-nums">{{ rangeCurrency(row, row.spend) }}</td>
                        <td v-if="showsRevenue" class="border-t px-6 py-4 text-right tabular-nums">{{ rangeCurrency(row, row.net) }}</td>
                        <td
                            v-if="showsRevenue"
                            class="border-t px-6 py-4 text-right tabular-nums font-medium"
                            :class="profitClass(row.net_profit)"
                        >
                            {{ rangeCurrency(row, row.net_profit) }}
                        </td>
                        <td v-if="showsRevenue" class="border-t px-6 py-4 text-right tabular-nums font-medium" :class="roasClass(row.roas)">{{ roas(row.roas) }}</td>
                        <td v-if="showsRegistrations" class="border-t px-6 py-4 text-right tabular-nums" :class="[ltvCacClass(row.ltv_cac), row.ltv_cac !== null ? 'font-medium' : '']">{{ row.cac === null ? '–' : currency(row.cac) }}</td>
                        <td v-if="showsSubscription" class="border-t px-6 py-4 text-right tabular-nums font-medium" :class="ltvCacClass(row.ltv_cac)">{{ formatLtvCac(row.ltv_cac) }}</td>
                        <td v-if="showsSubscription" class="border-t px-6 py-4 text-right tabular-nums font-medium" :class="paybackClass(row.cac_payback)">{{ formatPayback(row.cac_payback) }}</td>
                        <td class="w-px border-t px-6 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" aria-label="Analyze in dashboard" class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400" v-tooltip="'Analyze in dashboard'" @click="analyzeCampaign(row)">
                                    <DashboardIcon class="size-5" />
                                </button>
                                <button v-if="tracksSubscription(rowScope(row))" type="button" aria-label="Analyze revenue" class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400" v-tooltip="'Analyze revenue'" @click="analyzeCampaignRevenue(row)">
                                    <Wallet2Icon class="size-5" />
                                </button>
                                <button v-if="tracksUsers(rowScope(row))" type="button" aria-label="Analyze retention" class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400" v-tooltip="'Analyze retention'" @click="analyzeCampaignRetention(row)">
                                    <RetentionIcon class="size-5" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="inactiveCount">
                        <td class="border-t px-6 py-3" :colspan="columnCount + 1">
                            <button type="button" class="text-sm text-gray-500 hover:text-indigo-600 dark:text-dark-400 dark:hover:text-indigo-400" @click="showInactive = !showInactive">
                                {{ showInactive ? 'Hide' : 'Show' }} {{ inactiveCount }} {{ inactiveCount === 1 ? 'campaign' : 'campaigns' }} without activity in this range
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!campaigns.data.length">
                        <td class="border-t px-6 py-8 text-center text-gray-500 dark:text-dark-400" :colspan="columnCount + 1">
                            No campaigns yet. Track a campaign to enter its ad spend and start measuring ROAS, CAC and net profit.
                        </td>
                    </tr>
                </template>
            </ActionTable>
        </div>

        <CampaignModal
            :show="showCampaignModal"
            :projects="campaignProjectOptions"
            :defaultProjectId="defaultProjectId"
            @close="showCampaignModal = false"
        />
    </AppLayout>
</template>
