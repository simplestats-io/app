<script setup>
import {computed, ref} from 'vue';
import {useAiChat} from '@/Composables/useAiChat.js';
import {copyToClipboard} from '@/Helpers/ClipboardHelpers.js';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ShieldCheckIcon from 'bootstrap-icons/icons/shield-check.svg?component';
import CheckCircleIcon from 'bootstrap-icons/icons/check-circle-fill.svg?component';
import XCircleIcon from 'bootstrap-icons/icons/x-circle-fill.svg?component';
import SlashCircleIcon from 'bootstrap-icons/icons/slash-circle.svg?component';
import ClipboardIcon from 'bootstrap-icons/icons/clipboard.svg?component';
import ClipboardCheckIcon from 'bootstrap-icons/icons/clipboard-check.svg?component';

const props = defineProps({
    action: Object,
});

const aiChat = useAiChat();
const tokenCopied = ref(false);

const isOpen = computed(() => ['pending', 'busy'].includes(props.action.state));

const status = computed(() => ({
    done: {icon: CheckCircleIcon, class: 'text-green-600 dark:text-green-400'},
    failed: {icon: XCircleIcon, class: 'text-red-600 dark:text-red-400'},
    cancelled: {icon: SlashCircleIcon, class: 'text-gray-500 dark:text-gray-400'},
    superseded: {icon: SlashCircleIcon, class: 'text-gray-500 dark:text-gray-400'},
})[props.action.state] ?? null);

const copyToken = async () => {
    if (await copyToClipboard(props.action.apiToken)) {
        tokenCopied.value = true;
        setTimeout(() => tokenCopied.value = false, 2000);
    }
};
</script>

<template>
    <div class="rounded-lg border px-3 py-2.5 text-sm" :class="isOpen ? 'border-indigo-300 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-950/40' : 'dark:border-dark-700 bg-gray-50 dark:bg-dark-900'">
        <div class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
            <ShieldCheckIcon class="size-4 shrink-0 text-indigo-500" />
            {{ action.title }}
        </div>

        <dl class="mt-2 space-y-1">
            <div v-for="detail in action.details" :key="detail.label" class="flex gap-2">
                <dt class="shrink-0 text-gray-500 dark:text-gray-400">{{ detail.label }}:</dt>
                <dd class="min-w-0 break-words text-gray-900 dark:text-gray-100">{{ detail.value }}</dd>
            </div>
        </dl>

        <div v-if="isOpen" class="mt-3">
            <div class="flex items-center gap-2">
                <PrimaryButton type="button" :disabled="action.state === 'busy' || aiChat.isStreaming.value" @click="aiChat.confirmAction(action)">
                    Confirm
                </PrimaryButton>
                <SecondaryButton type="button" :disabled="action.state === 'busy' || aiChat.isStreaming.value" @click="aiChat.cancelAction(action)">
                    Cancel
                </SecondaryButton>
            </div>
            <p v-if="action.state === 'pending'" class="mt-2 text-xs text-gray-500 dark:text-gray-400">Nothing changes until you confirm.</p>
        </div>

        <div v-else-if="status" class="mt-3 flex items-start gap-2" :class="status.class">
            <component :is="status.icon" class="mt-0.5 size-4 shrink-0" />
            <span>{{ action.resultMessage }}</span>
        </div>

        <div v-if="action.apiToken" class="mt-2 rounded-md border border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-2.5 py-2">
            <p class="text-xs text-amber-800 dark:text-amber-300">API token of the new project, shown only this once. Set it as SIMPLESTATS_API_TOKEN in the .env of your app.</p>
            <div class="mt-1.5 flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate text-xs text-gray-900 dark:text-gray-100">{{ action.apiToken }}</code>
                <button type="button" aria-label="Copy API token" v-tooltip="tokenCopied ? 'Copied' : 'Copy'" @click="copyToken()" class="p-1 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <component :is="tokenCopied ? ClipboardCheckIcon : ClipboardIcon" class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>
