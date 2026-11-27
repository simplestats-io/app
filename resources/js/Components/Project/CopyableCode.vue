<script setup>
import {ref} from "vue";
import {copyToClipboard} from "@/Helpers/ClipboardHelpers.js";
import ClipboardIcon from "bootstrap-icons/icons/clipboard.svg?component";
import ClipboardCheckIcon from "bootstrap-icons/icons/clipboard-check.svg?component";

const props = defineProps({
    code: {type: String, required: true},
    copyLabel: {type: String, default: 'Copy'},
});

const copied = ref(false);

const copy = async () => {
    copied.value = await copyToClipboard(props.code);
};
</script>

<template>
    <div class="flex justify-between gap-3 bg-gray-100 dark:bg-dark-900 px-4 py-2 rounded font-mono text-sm text-gray-500 dark:text-dark-400">
        <pre class="min-w-0 flex-1 overflow-x-auto whitespace-pre font-mono"><slot>{{ code }}</slot></pre>
        <div>
            <button type="button" :aria-label="copyLabel" @click="copy">
                <ClipboardCheckIcon v-if="copied" class="size-5 -my-1.5 text-green-600 dark:text-green-500" />
                <ClipboardIcon v-else class="size-5 -my-1.5 hover:scale-110" />
            </button>
        </div>
    </div>
</template>
