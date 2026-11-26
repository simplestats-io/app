<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import CampaignFilter from "@/Pages/Campaigns/Partials/CampaignFilter.vue";
import SubscriptionPlanAlarm from "@/Pages/Dashboard/Partials/SubscriptionPlanAlarm.vue";
import ActionTable from "@/Components/ActionTable.vue";
import AdSpendModal from "@/Pages/Campaigns/Partials/AdSpendModal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import TextInput from "@/Components/TextInput.vue";
import InputError from "@/Components/InputError.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import ConfirmationModal from "@/Components/ConfirmationModal.vue";
import {Link, router, useForm, usePage} from "@inertiajs/vue3";
import {ref} from "vue";
import {useUsage} from "@/Composables/useUsage.js";
import {formatMap} from "@/Helpers/NumberHelpers.js";
import {
    formatLtvCac,
    formatPayback,
    formatRoas,
    ltvCacClass as ltvCacClassBase,
    paybackClass as paybackClassBase,
    profitClass as profitClassBase,
    roasClass as roasClassBase
} from "@/Helpers/CampaignKpiHelpers.js";
import {campaignMetricInfo as metricInfo} from "@/Data/CampaignMetricInfo.js";
import XLgIcon from "bootstrap-icons/icons/x-lg.svg?component";
import PencilIcon from "bootstrap-icons/icons/pencil.svg?component";
import TrashIcon from "bootstrap-icons/icons/trash.svg?component";
import DashboardIcon from "bootstrap-icons/icons/grid.svg?component";
import Wallet2Icon from "bootstrap-icons/icons/wallet2.svg?component";
import RetentionIcon from "bootstrap-icons/icons/activity.svg?component";

const props = defineProps({
    teamStats: Object,
    presets: Array,
    filters: Object,
    rangeTypes: Array,
    subscriptionPlanAlarm: Array,
    usageBits: Object,
    campaign: Object,
    summary: Object,
    traffic: {type: Object, default: () => ({page_views: false, bounce_rate: null, spend_by_source: false, spend_unassigned: 0, sources: []})},
    adSpends: Object,
    linkedTrackCampaigns: {type: Array, default: () => []},
    linkableTrackCampaigns: {type: Array, default: () => []},
});

const page = usePage();

const currentProject = props.teamStats.projects[props.summary?.project_uuid] ?? props.teamStats.projects[0];
const {currentProjectHasUsages} = useUsage(currentProject, props.usageBits);

const showsVisitors = currentProjectHasUsages(['visitors']);
const showsRevenue = currentProjectHasUsages(['payments']);
const showsRegistrations = currentProjectHasUsages(['users']);
const showsSubscription = currentProjectHasUsages(['subscription']);

const neutral = 'text-gray-950 dark:text-white';
const currency = (cents, code = page.props.teamCurrency) => formatMap.currency(cents, {notation: 'standard'}, code);
const number = (value) => formatMap.number(value, {notation: 'standard'});
const percent = (value) => value === null ? '–' : formatMap.percent(value, {minimumFractionDigits: 1, maximumFractionDigits: 1});
const roas = formatRoas;
const roasClass = (value) => roasClassBase(value, neutral);
const profitClass = (value) => profitClassBase(value, neutral);
const ltvCacClass = (value) => ltvCacClassBase(value, neutral);
const paybackClass = (value) => paybackClassBase(value, neutral);

const analyzeIn = (routeName) => {
    router.get(route(routeName, {...props.filters, project_id: props.summary?.project_uuid ?? 0, campaign: props.campaign.uuid}));
};

const showModal = ref(false);
const editSpend = ref(null);
const deleting = ref(null);

const openAdd = () => {
    editSpend.value = null;
    showModal.value = true;
};

const openEdit = (spend) => {
    editSpend.value = spend;
    showModal.value = true;
};

const remove = () => {
    router.delete(route('ad-spends.destroy', deleting.value), {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
};

const editingName = ref(false);
const nameForm = useForm({name: props.campaign.name});

const startEditName = () => {
    nameForm.name = props.campaign.name;
    nameForm.clearErrors();
    editingName.value = true;
};

const cancelEditName = () => {
    editingName.value = false;
    nameForm.reset();
    nameForm.clearErrors();
};

const saveName = () => {
    nameForm.patch(route('campaigns.update', props.campaign), {
        preserveScroll: true,
        onSuccess: () => {
            editingName.value = false;
        },
    });
};

const linkForm = useForm({name: ''});

const link = () => {
    linkForm.post(route('campaigns.track-campaigns.link', props.campaign), {
        preserveScroll: true,
        onSuccess: () => linkForm.reset(),
    });
};

const unlink = (trackCampaign) => {
    router.delete(route('campaigns.track-campaigns.unlink', [props.campaign, trackCampaign]), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :title="campaign.name" :fullscreen="true">
        <div v-if="subscriptionPlanAlarm.length > 0" class="mb-6">
            <SubscriptionPlanAlarm :subscriptionPlanAlarm="subscriptionPlanAlarm" />
        </div>

        <div class="space-y-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1">
                    <h1 v-if="!editingName" class="flex items-center gap-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        <span>Campaign: <span class="italic">{{ campaign.name }}</span></span>
                        <button
                            type="button"
                            aria-label="Rename campaign"
                            class="text-gray-400 hover:text-gray-600 dark:text-dark-400 dark:hover:text-dark-200"
                            v-tooltip="'Rename campaign'"
                            @click="startEditName"
                        >
                            <PencilIcon class="size-4" />
                        </button>
                    </h1>
                    <template v-else>
                        <form @submit.prevent="saveName" class="flex items-center gap-2">
                            <TextInput
                                v-model="nameForm.name"
                                type="text"
                                class="block w-72 py-1.5 text-lg font-bold"
                                autofocus
                                @keyup.esc="cancelEditName"
                            />
                            <PrimaryButton type="submit" :class="{ 'opacity-25': nameForm.processing }" :disabled="nameForm.processing">
                                Save
                            </PrimaryButton>
                            <SecondaryButton type="button" @click="cancelEditName">Cancel</SecondaryButton>
                        </form>
                        <InputError :message="nameForm.errors.name" class="mt-1" />
                    </template>
                    <p v-if="summary?.project_name" class="text-sm text-gray-500 dark:text-dark-400">{{ summary.project_name }}</p>
                </div>
                <CampaignFilter
                    :teamStats="teamStats"
                    :filters="filters"
                    :presets="presets"
                    :rangeTypes="rangeTypes"
                    submitRoute="campaigns.show"
                    :submitRouteParams="{campaign: campaign.uuid}"
                />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-500 dark:text-dark-400 mr-1">Analyse this campaign in</span>
                <SecondaryButton @click="analyzeIn('dashboard')">
                    <DashboardIcon class="size-4 mr-1.5" /> Dashboard
                </SecondaryButton>
                <SecondaryButton v-if="showsSubscription" @click="analyzeIn('revenue')">
                    <Wallet2Icon class="size-4 mr-1.5" /> Revenue
                </SecondaryButton>
                <SecondaryButton v-if="showsRegistrations" @click="analyzeIn('retention')">
                    <RetentionIcon class="size-4 mr-1.5" /> Retention
                </SecondaryButton>
            </div>

            <div class="space-y-3">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-7 gap-3">
                    <div class="bg-white dark:bg-dark-800 border rounded-md py-3 px-4">
                        <div class="flex items-center gap-1 text-sm font-medium tracking-wider text-gray-700 dark:text-dark-400">
                            Spend <InfoTooltip :title="metricInfo.spend.title" :text="metricInfo.spend.text" />
                        </div>
                        <div class="mt-1 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ currency(summary.spend) }}</div>
                        <div v-if="summary.spend < summary.spend_total" class="text-xs text-gray-500 dark:text-dark-400">
                            of {{ currency(summary.spend_total) }} total
                        </div>
                    </div>
                    <div v-if="showsRevenue" class="bg-white dark:bg-dark-800 border rounded-md py-3 px-4">
                        <div class="flex items-center gap-1 text-sm font-medium tracking-wider text-gray-700 dark:text-dark-400">
                            Revenue <InfoTooltip :title="metricInfo.revenue.title" :text="metricInfo.revenue.text" />
                        </div>
                        <div class="mt-1 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ currency(summary.net) }}</div>
                    </div>
                    <div v-if="showsRevenue" class="bg-white dark:bg-dark-800 border rounded-md py-3 px-4">
                        <div class="flex items-center gap-1 text-sm font-medium tracking-wider text-gray-700 dark:text-dark-400">
                            Net profit <InfoTooltip :title="metricInfo.netProfit.title" :text="metricInfo.netProfit.text" />
                        </div>
                        <div class="mt-1 text-xl font-semibold tracking-tight" :class="profitClass(summary.net_profit)">
                            {{ currency(summary.net_profit) }}
                        </div>
                    </div>
                    <div v-if="showsRevenue" class="bg-white dark:bg-dark-800 border rounded-md py-3 px-4">
                        <div class="flex items-center gap-1 text-sm font-medium tracking-wider text-gray-700 dark:text-dark-400">
                            ROAS <InfoTooltip :title="metricInfo.roas.title" :text="metricInfo.roas.text" />
                        </div>
                        <div class="mt-1 text-xl font-semibold tracking-tight" :class="roasClass(summary.roas)">{{ roas(summary.roas) }}</div>
                    </div>
                    <div v-if="showsRegistrations" class="bg-white dark:bg-dark-800 border rounded-md py-3 px-4">
                        <div class="flex items-center gap-1 text-sm font-medium tracking-wider text-gray-700 dark:text-dark-400">
                            CAC <InfoTooltip :title="metricInfo.cac.title" :text="metricInfo.cac.text" />
                        </div>
                        <div class="mt-1 text-xl font-semibold tracking-tight" :class="ltvCacClass(summary.ltv_cac)">{{ summary.cac === null ? '–' : currency(summary.cac) }}</div>
                    </div>
                    <div v-if="showsSubscription" class="bg-white dark:bg-dark-800 border rounded-md py-3 px-4">
                        <div class="flex items-center gap-1 text-sm font-medium tracking-wider text-gray-700 dark:text-dark-400">
                            LTV:CAC <InfoTooltip :title="metricInfo.ltvCac.title" :text="metricInfo.ltvCac.text" />
                        </div>
                        <div class="mt-1 text-xl font-semibold tracking-tight" :class="ltvCacClass(summary.ltv_cac)">{{ formatLtvCac(summary.ltv_cac) }}</div>
                    </div>
                    <div v-if="showsSubscription" class="bg-white dark:bg-dark-800 border rounded-md py-3 px-4">
                        <div class="flex items-center gap-1 text-sm font-medium tracking-wider text-gray-700 dark:text-dark-400">
                            Payback <InfoTooltip :title="metricInfo.cacPayback.title" :text="metricInfo.cacPayback.text" />
                        </div>
                        <div class="mt-1 text-xl font-semibold tracking-tight" :class="paybackClass(summary.cac_payback)">{{ formatPayback(summary.cac_payback) }}</div>
                    </div>
                </div>

                <div v-if="showsVisitors" class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="bg-white dark:bg-dark-800 border rounded-md py-2 px-4 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1 text-sm text-gray-700 dark:text-dark-400">
                            Visitors <InfoTooltip :title="metricInfo.visitors.title" :text="metricInfo.visitors.text" />
                        </div>
                        <div class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ number(summary.visitors) }}</div>
                    </div>
                    <div class="bg-white dark:bg-dark-800 border rounded-md py-2 px-4 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1 text-sm text-gray-700 dark:text-dark-400">
                            Cost per visitor <InfoTooltip :title="metricInfo.costPerVisitor.title" :text="metricInfo.costPerVisitor.text" />
                        </div>
                        <div class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ summary.cost_per_visitor === null ? '–' : currency(summary.cost_per_visitor) }}</div>
                    </div>
                    <div v-if="showsRegistrations" class="bg-white dark:bg-dark-800 border rounded-md py-2 px-4 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1 text-sm text-gray-700 dark:text-dark-400">
                            Registrations <InfoTooltip :title="metricInfo.registrations.title" :text="metricInfo.registrations.text" />
                        </div>
                        <div class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ number(summary.reg) }}</div>
                    </div>
                    <div v-if="traffic.page_views" class="bg-white dark:bg-dark-800 border rounded-md py-2 px-4 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1 text-sm text-gray-700 dark:text-dark-400">
                            Bounce rate <InfoTooltip :title="metricInfo.bounceRate.title" :text="metricInfo.bounceRate.text" />
                        </div>
                        <div class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ percent(traffic.bounce_rate) }}</div>
                    </div>
                </div>
            </div>

            <div v-if="traffic.sources.length > 1 || traffic.spend_by_source" class="bg-white dark:bg-dark-800 border dark:border-dark-700 rounded-md">
                <div class="px-4 py-3 border-b dark:border-dark-700">
                    <h2 class="font-semibold">By source</h2>
                    <p class="text-sm text-gray-500 dark:text-dark-400">
                        This campaign's traffic per <span class="font-mono">utm_source</span> in the selected period.
                        <template v-if="!traffic.spend_by_source">Give an ad spend entry its <span class="font-mono">utm_source</span> to see spend and cost per visitor per source.</template>
                        <template v-else-if="traffic.spend_unassigned > 0">{{ currency(traffic.spend_unassigned) }} of the spend has no <span class="font-mono">utm_source</span> and is not split.</template>
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-700 dark:text-dark-300">
                                <th class="px-4 py-2 font-semibold">Source</th>
                                <th class="px-4 py-2 font-semibold text-right">Visitors</th>
                                <th v-if="showsRegistrations" class="px-4 py-2 font-semibold text-right">Registrations</th>
                                <th v-if="traffic.page_views" class="px-4 py-2 font-semibold text-right">Bounce rate</th>
                                <th v-if="showsRevenue" class="px-4 py-2 font-semibold text-right">Revenue</th>
                                <th v-if="traffic.spend_by_source" class="px-4 py-2 font-semibold text-right">Spend</th>
                                <th v-if="traffic.spend_by_source" class="px-4 py-2 font-semibold text-right">Cost per visitor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(source, index) in traffic.sources" :key="index" class="border-t dark:border-dark-700 text-gray-700 dark:text-dark-300">
                                <td v-if="source.name !== null" class="px-4 py-2 font-mono">{{ source.name }}</td>
                                <td v-else class="px-4 py-2 italic text-gray-500 dark:text-dark-400">No utm_source</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ number(source.visitors) }}</td>
                                <td v-if="showsRegistrations" class="px-4 py-2 text-right tabular-nums">{{ number(source.reg) }}</td>
                                <td v-if="traffic.page_views" class="px-4 py-2 text-right tabular-nums">{{ percent(source.bounce_rate) }}</td>
                                <td v-if="showsRevenue" class="px-4 py-2 text-right tabular-nums">{{ currency(source.net) }}</td>
                                <td v-if="traffic.spend_by_source" class="px-4 py-2 text-right tabular-nums">{{ source.spend === null ? '–' : currency(source.spend) }}</td>
                                <td v-if="traffic.spend_by_source" class="px-4 py-2 text-right tabular-nums">{{ source.cost_per_visitor === null ? '–' : currency(source.cost_per_visitor) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white dark:bg-dark-800 border dark:border-dark-700 rounded-md">
                <div class="px-4 py-3 border-b dark:border-dark-700">
                    <h2 class="font-semibold">Linked utm_campaign values</h2>
                    <p class="text-sm text-gray-500 dark:text-dark-400">
                        Traffic and revenue tracked under these <span class="font-mono">utm_campaign</span> values counts toward this campaign.<br>Link one before launch and it attaches automatically once the first visitor arrives.
                    </p>
                </div>

                <div class="p-4 space-y-3">
                    <div v-if="linkedTrackCampaigns.length" class="flex flex-wrap gap-2">
                        <Link
                            v-for="utm in linkedTrackCampaigns"
                            :key="utm.id"
                            :href="route('dashboard', {...filters, project_id: summary?.project_uuid ?? 0, track_campaign: utm.id})"
                            class="inline-flex items-center gap-1 bg-indigo-100 dark:bg-indigo-500 dark:text-dark-100 hover:bg-indigo-200 dark:hover:bg-indigo-400 border rounded-md px-2 py-1 text-sm font-mono"
                            v-tooltip="'Analyse this utm_campaign in the dashboard'"
                        >
                            {{ utm.name }}
                            <button type="button" aria-label="Unlink" class="text-indigo-400 hover:text-red-700 dark:text-indigo-100 dark:hover:text-red-300" v-tooltip="'Unlink'" @click.stop.prevent="unlink(utm)">
                                <XLgIcon class="size-4" />
                            </button>
                        </Link>
                    </div>
                    <p v-else class="text-sm text-gray-500 dark:text-dark-400">
                        No utm_campaign values linked yet. Revenue stays at zero until you link at least one.
                    </p>

                    <form @submit.prevent="link" class="flex items-center gap-2">
                        <div class="flex-1 max-w-sm">
                            <TextInput
                                v-model="linkForm.name"
                                type="text"
                                list="linkable-utms"
                                class="block w-full font-mono py-1.5 text-sm"
                                placeholder="exact utm_campaign value"
                            />
                            <datalist id="linkable-utms">
                                <option v-for="utm in linkableTrackCampaigns" :key="utm.uuid" :value="utm.name" />
                            </datalist>
                        </div>
                        <SecondaryButton type="submit" :class="{ 'opacity-25': linkForm.processing }" :disabled="linkForm.processing">
                            Link
                        </SecondaryButton>
                        <div>
                            <InputError :message="linkForm.errors.name" class="mt-1" />
                        </div>
                    </form>
                </div>
            </div>

            <ActionTable :links="adSpends.links" maxWidth="max-w-none">
                <template #title>Ad spend entries</template>
                <template #description>All budgets for this campaign. Click a row to edit it.</template>
                <template #aside>
                    <PrimaryButton @click="openAdd">Add ad spend</PrimaryButton>
                </template>

                <template #content>
                    <tr class="text-left font-bold text-gray-700 dark:text-dark-300">
                        <th class="pb-4 pt-6 px-6">Name</th>
                        <th class="pb-4 pt-6 px-6">Source</th>
                        <th class="pb-4 pt-6 px-6">Amount</th>
                        <th class="pb-4 pt-6 px-6">Period</th>
                        <th class="pb-4 pt-6 px-6"></th>
                    </tr>
                    <tr
                        v-for="spend in adSpends.data"
                        :key="spend.uuid"
                        class="cursor-pointer hover:bg-gray-50 dark:hover:bg-dark-700 text-gray-700 dark:text-dark-300"
                        @click="openEdit(spend)"
                    >
                        <td class="border-t px-6 py-4">{{ spend.name ?? '–' }}</td>
                        <td class="border-t px-6 py-4 font-mono">{{ spend.utm_source ?? '–' }}</td>
                        <td class="border-t px-6 py-4 tabular-nums">{{ currency(spend.amount, spend.currency) }}</td>
                        <td class="border-t px-6 py-4 text-gray-500 dark:text-dark-400">{{ spend.starts_on }} → {{ spend.ends_on }}</td>
                        <td class="w-px border-t px-6 py-4">
                            <button
                                type="button"
                                aria-label="Delete"
                                class="text-gray-400 hover:text-red-600 dark:hover:text-red-400"
                                @click.stop="deleting = spend"
                            >
                                <TrashIcon class="size-5" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!adSpends.data.length">
                        <td class="border-t px-6 py-8 text-center text-gray-500 dark:text-dark-400" colspan="5">
                            No ad spend yet. Add a budget to track this campaign's profitability.
                        </td>
                    </tr>
                </template>
            </ActionTable>
        </div>

        <AdSpendModal
            :show="showModal"
            :editSpend="editSpend"
            :campaign="campaign"
            :teamCurrency="page.props.teamCurrency"
            :sourceOptions="traffic.sources.map((source) => source.name).filter((name) => name !== null)"
            @close="showModal = false"
        />

        <ConfirmationModal :show="deleting !== null" @close="deleting = null">
            <template #title>Delete ad spend</template>
            <template #content>
                Are you sure you want to delete this ad spend entry for <span class="font-bold">{{ campaign.name }}</span>?
            </template>
            <template #footer>
                <SecondaryButton @click="deleting = null">Cancel</SecondaryButton>
                <DangerButton class="ms-3" @click="remove">Delete</DangerButton>
            </template>
        </ConfirmationModal>
    </AppLayout>
</template>
