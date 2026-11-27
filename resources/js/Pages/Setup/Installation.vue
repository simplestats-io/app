<script setup>
import PrimaryButton from "@/Components/PrimaryButton.vue";
import FullscreenCard from "@/Components/FullscreenCard.vue";
import InstallationSteps from "@/Components/Project/InstallationSteps.vue";
import NoDataYetHint from "@/Components/Project/NoDataYetHint.vue";
import {useFirstDataPolling} from "@/Composables/useFirstDataPolling.js";
import CheckCircleFillIcon from "bootstrap-icons/icons/check-circle-fill.svg?component";
import {router} from "@inertiajs/vue3";

defineProps({
    apiToken: String,
    domain: String,
    tracking: Array,
});

const {received, takingLong} = useFirstDataPolling();

const submit = () => {
    router.get(route('dashboard'));
};
</script>

<template>
    <FullscreenCard title="Installation" description="Complete the setup." wide>
        <form @submit.prevent="submit">
            <InstallationSteps :apiToken="apiToken" :domain="domain" :tracking="tracking" />

            <div class="mt-6 rounded-lg border px-4 py-3 dark:border-dark-600" aria-live="polite">
                <div v-if="received" class="flex items-center gap-2 text-sm font-medium text-green-600 dark:text-green-500">
                    <CheckCircleFillIcon class="size-4 shrink-0" />
                    First data received. Your dashboard is ready.
                </div>
                <template v-else>
                    <div class="flex items-center gap-3 text-sm">
                        <span class="relative flex size-2.5 shrink-0">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-indigo-400 opacity-75"></span>
                            <span class="relative inline-flex size-2.5 rounded-full bg-indigo-500"></span>
                        </span>
                        Waiting for your first data. Open your site in a browser once the client is installed.
                    </div>
                    <NoDataYetHint v-if="takingLong" class="mt-2 pl-5" />
                </template>
            </div>

            <div class="mt-6">
                <PrimaryButton class="w-full !text-sm justify-center !p-3">
                    Go to dashboard
                </PrimaryButton>
            </div>
        </form>
    </FullscreenCard>
</template>
