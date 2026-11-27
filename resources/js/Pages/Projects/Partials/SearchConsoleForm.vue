<script setup>
import {computed, ref, watch} from 'vue';
import {Deferred, router, useForm} from '@inertiajs/vue3';
import ActionSection from '@/Components/ActionSection.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import GoogleIcon from 'bootstrap-icons/icons/google.svg?component';
import ExclamationIcon from 'bootstrap-icons/icons/exclamation-triangle-fill.svg?component';
import CheckIcon from 'bootstrap-icons/icons/check-circle-fill.svg?component';

const props = defineProps({
    project: Object,
    connection: Object,
    reusableAccounts: {type: Array, default: () => []},
    sites: Object,
    canUpdate: Boolean,
});

const appName = import.meta.env.VITE_APP_NAME;
const confirmingDisconnect = ref(false);
const form = useForm({site_url: null});

// The deferred sites arrive after mount: preselect the property matching the project domain.
watch(() => props.sites?.suggested, (suggested) => {
    if (suggested && ! form.site_url) {
        form.site_url = suggested;
    }
}, {immediate: true});

const reusing = ref(false);
const connectWithExisting = (googleEmail) => {
    reusing.value = true;
    router.post(route('gsc-connections.store-from-existing', props.project), {google_email: googleEmail}, {
        preserveScroll: true,
        onFinish: () => reusing.value = false,
    });
};

const siteOptions = computed(() => (props.sites?.sites ?? []).map((site) => ({
    id: site,
    name: site.startsWith('sc-domain:') ? `${site.replace('sc-domain:', '')} (domain)` : site,
})));

const lastSynced = computed(() => props.connection?.last_synced_at
    ? new Date(props.connection.last_synced_at).toLocaleString()
    : null);

const linkSite = () => {
    form.put(route('gsc-connections.update', props.project), {preserveScroll: true});
};

const disconnect = () => {
    router.delete(route('gsc-connections.destroy', props.project), {
        preserveScroll: true,
        onFinish: () => confirmingDisconnect.value = false,
    });
};
</script>

<template>
    <ActionSection id="search-console">
        <template #title>
            Google Search Console
        </template>

        <template #description>
            See which Google search keywords bring visitors, with clicks, ranking and estimated revenue per keyword.
            <a href="/docs/google-search-console.html" target="_blank" class="underline">Learn more</a>
        </template>

        <template #content>
            <div v-if="! connection" class="max-w-xl text-sm text-gray-600 dark:text-dark-400">
                <p>
                    Connect the Google account that has access to this site in Search Console.
                    {{ appName }} only gets read access to your search data.
                </p>

                <div v-if="canUpdate" class="mt-5 flex flex-wrap gap-3">
                    <PrimaryButton v-for="account in reusableAccounts" :key="account" type="button" :disabled="reusing" @click="connectWithExisting(account)">
                        <GoogleIcon class="size-4 mr-2 shrink-0" />
                        Connect with {{ account }}
                    </PrimaryButton>
                    <a :href="route('gsc-connections.create', project)">
                        <component :is="reusableAccounts.length ? SecondaryButton : PrimaryButton" type="button">
                            <GoogleIcon class="size-4 mr-2 shrink-0" />
                            {{ reusableAccounts.length ? 'Use another Google account' : 'Connect Google Search Console' }}
                        </component>
                    </a>
                </div>
                <p v-else class="mt-5">Ask an administrator or editor of your team to connect it.</p>
            </div>

            <div v-else class="max-w-xl text-sm text-gray-600 dark:text-dark-400 space-y-5">
                <div v-if="connection.reconnect_required_at" class="flex items-start gap-2 text-red-600 dark:text-red-400">
                    <ExclamationIcon class="size-4 mt-0.5 shrink-0" />
                    <span>Google no longer grants access to this Search Console data. Reconnect to resume the daily import.</span>
                </div>

                <div v-else-if="connection.site_url" class="flex items-start gap-2">
                    <CheckIcon class="size-4 mt-0.5 shrink-0 text-green-500" />
                    <span>
                        Linked to <strong class="text-gray-900 dark:text-white">{{ connection.site_url }}</strong>
                        <template v-if="connection.google_email">via {{ connection.google_email }}.</template>
                        <template v-if="lastSynced">Last import: {{ lastSynced }}.</template>
                        <template v-else>Importing up to 16 months of keywords, this takes a few minutes.</template>
                    </span>
                </div>

                <div v-else-if="connection.connected_by_user">
                    Connected as {{ connection.google_email }}. Pick the Search Console property of this project.
                </div>
                <div v-else>
                    Connected as {{ connection.google_email }}. The person who connected it picks the Search Console property.
                </div>

                <Deferred v-if="canUpdate && connection.connected_by_user && ! connection.site_url && ! connection.reconnect_required_at" data="gscSites">
                    <template #fallback>
                        <div class="h-10 w-full max-w-md rounded bg-gray-100 dark:bg-dark-700 animate-pulse" />
                    </template>

                    <div v-if="sites?.error === 'reconnect'" class="text-red-600 dark:text-red-400">
                        Google no longer grants access. Please reconnect.
                    </div>
                    <div v-else-if="sites?.error">
                        The properties could not be loaded from Google right now. Please try again later.
                    </div>
                    <div v-else-if="! siteOptions.length">
                        This Google account has no verified Search Console property. Add your site in Search Console first or connect another account.
                    </div>
                    <form v-else class="flex flex-col sm:flex-row gap-3 sm:items-start" @submit.prevent="linkSite">
                        <div class="w-full sm:max-w-sm">
                            <SelectInput v-model="form.site_url" :options="siteOptions" class="w-full" />
                            <InputError :message="form.errors.site_url" class="mt-2" />
                        </div>
                        <PrimaryButton :disabled="form.processing || ! form.site_url">
                            Link property
                        </PrimaryButton>
                    </form>
                </Deferred>

                <div v-if="canUpdate" class="flex gap-3">
                    <a v-if="connection.reconnect_required_at" :href="route('gsc-connections.create', project)">
                        <PrimaryButton type="button">
                            <GoogleIcon class="size-4 mr-2" />
                            Reconnect
                        </PrimaryButton>
                    </a>
                    <DangerButton type="button" @click="confirmingDisconnect = true">
                        Disconnect
                    </DangerButton>
                </div>
            </div>

            <ConfirmationModal :show="confirmingDisconnect" @close="confirmingDisconnect = false">
                <template #title>
                    Disconnect Google Search Console
                </template>

                <template #content>
                    The imported keywords of this project are deleted and the access to your Search Console data is revoked. You can connect it again at any time.
                </template>

                <template #footer>
                    <SecondaryButton @click="confirmingDisconnect = false">
                        Cancel
                    </SecondaryButton>

                    <DangerButton class="ms-3" @click="disconnect">
                        Disconnect
                    </DangerButton>
                </template>
            </ConfirmationModal>
        </template>
    </ActionSection>
</template>
