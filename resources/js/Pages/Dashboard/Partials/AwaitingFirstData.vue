<script setup>
import {onBeforeUnmount, onMounted, ref} from "vue";
import {router} from "@inertiajs/vue3";
import axios from "axios";
import Modal from "@/Components/Modal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import InstallationSteps from "@/Components/Project/InstallationSteps.vue";
import CheckCircleFillIcon from "bootstrap-icons/icons/check-circle-fill.svg?component";

defineProps({
    project: Object,
    token: {type: String, default: null},
    canShowTour: {type: Boolean, default: false},
});

const emit = defineEmits(['done', 'tour']);

const POLL_INTERVAL_MS = 5000;
// Lets the empty dashboard register before the modal covers it.
const MODAL_DELAY_MS = 600;

const appName = import.meta.env.VITE_APP_NAME;

const showModal = ref(false);
const received = ref(false);

let pollTimer = null;
let delayTimer = null;

async function checkForData() {
    if (document.hidden) {
        return;
    }

    try {
        const {data} = await axios.get(route('stats.status'));

        if (data.hasData) {
            clearInterval(pollTimer);
            received.value = true;
            showModal.value = true;
            router.reload();
        }
    } catch {
        // A failed check is retried on the next tick.
    }
}

onMounted(() => {
    delayTimer = setTimeout(() => showModal.value = true, MODAL_DELAY_MS);
    pollTimer = setInterval(checkForData, POLL_INTERVAL_MS);
});

onBeforeUnmount(() => {
    clearTimeout(delayTimer);
    clearInterval(pollTimer);
});
</script>

<template>
    <div>
        <div v-if="! received" class="flex items-center justify-between gap-4 bg-white dark:bg-dark-800 border rounded-md px-5 py-3 mb-6">
            <div class="flex items-center gap-3 text-sm">
                <span class="relative flex size-2.5 shrink-0">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-indigo-400 opacity-75"></span>
                    <span class="relative inline-flex size-2.5 rounded-full bg-indigo-500"></span>
                </span>
                <span>Waiting for the first data from <strong>{{ project.name }}</strong>.</span>
            </div>
            <PrimaryButton type="button" @click="showModal = true">
                Show setup
            </PrimaryButton>
        </div>

        <Modal :show="showModal" :closeable="false">
            <div v-if="received" class="px-6 py-10 text-center">
                <CheckCircleFillIcon class="size-12 mx-auto text-green-500" />
                <h2 class="mt-4 text-lg font-semibold">First data received!</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-dark-400">
                    <strong>{{ project.name }}</strong> is now sending data to {{ appName }}.
                </p>
                <div class="mt-8 flex justify-center gap-3">
                    <SecondaryButton v-if="canShowTour" @click="emit('tour')">
                        Show me around
                    </SecondaryButton>
                    <PrimaryButton type="button" @click="emit('done')">
                        Go to dashboard
                    </PrimaryButton>
                </div>
            </div>

            <div v-else class="px-6 py-6 sm:px-8">
                <div class="flex items-center gap-3 mb-2">
                    <span class="relative flex size-3 shrink-0">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-indigo-400 opacity-75"></span>
                        <span class="relative inline-flex size-3 rounded-full bg-indigo-500"></span>
                    </span>
                    <h2 class="text-lg font-semibold">Almost there! Waiting for your first data</h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-dark-400 mb-8">
                    <strong>{{ project.name }}</strong> has not received any data yet. Install the client in your Laravel app and open the app in a browser.
                    This window updates automatically as soon as the first data arrives.
                </p>

                <InstallationSteps :apiToken="token" />

                <div class="mt-8 text-right">
                    <button type="button" class="text-sm text-gray-500 dark:text-dark-400 underline hover:text-gray-700 dark:hover:text-dark-300" @click="showModal = false">
                        I'll do this later
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
