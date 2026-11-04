<script setup>
import {copyToClipboard} from "@/Helpers/ClipboardHelpers.js";
import ClipboardIcon from "bootstrap-icons/icons/clipboard.svg?component";

const props = defineProps({
    apiToken: {type: String, default: null},
});

const appName = import.meta.env.VITE_APP_NAME;
const isSelfHosted = import.meta.env.VITE_APP_TYPE !== 'saas';

const composerInstallCmd = 'composer require simplestats-io/laravel-client';
const publishConfigCmd = 'php artisan vendor:publish --tag="simplestats-client-config"';

const apiUrl = `${window.location.origin}/api/v1`;

const envBlock = isSelfHosted
    ? `SIMPLESTATS_API_URL=${apiUrl}\nSIMPLESTATS_API_TOKEN=${props.apiToken ?? ''}`
    : `SIMPLESTATS_API_TOKEN=${props.apiToken ?? ''}`;
</script>

<template>
    <div class="space-y-6">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Install the {{ appName }} client
            </label>
            <div class="flex justify-between bg-gray-100 dark:bg-dark-900 px-4 py-2 rounded font-mono text-sm text-gray-500 dark:text-dark-400 break-all">
                <div class="truncate">
                    {{ composerInstallCmd }}
                </div>
                <div>
                    <button type="button" aria-label="Copy command" @click="copyToClipboard(composerInstallCmd)">
                        <ClipboardIcon class="size-5 -my-1.5 hover:scale-110" />
                    </button>
                </div>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Publish the config
            </label>
            <div class="flex justify-between bg-gray-100 dark:bg-dark-900 px-4 py-2 rounded font-mono text-sm text-gray-500 dark:text-dark-400 break-all">
                <div class="truncate">
                    {{ publishConfigCmd }}
                </div>
                <div>
                    <button type="button" aria-label="Copy command" @click="copyToClipboard(publishConfigCmd)">
                        <ClipboardIcon class="size-5 -my-1.5 hover:scale-110" />
                    </button>
                </div>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Add the following credentials to your environment file
            </label>
            <div v-if="apiToken" class="flex justify-between bg-gray-100 dark:bg-dark-900 px-4 py-2 rounded font-mono text-sm text-gray-500 dark:text-dark-400 break-all">
                <div class="min-w-0 flex-1">
                    <div v-if="isSelfHosted" class="truncate">
                        <span class="text-blue-600 dark:text-blue-500">SIMPLESTATS_API_URL=</span><span class="text-green-600 dark:text-green-500">{{ apiUrl }}</span>
                    </div>
                    <div class="truncate">
                        <span class="text-blue-600 dark:text-blue-500">SIMPLESTATS_API_TOKEN=</span><span class="text-green-600 dark:text-green-500">{{ apiToken }}</span>
                    </div>
                </div>
                <div>
                    <button type="button" aria-label="Copy credentials" @click="copyToClipboard(envBlock)">
                        <ClipboardIcon class="size-5 -my-1.5 hover:scale-110" />
                    </button>
                </div>
            </div>
            <p v-else class="text-sm text-gray-600 dark:text-dark-400">
                Ask a team administrator for the project's API token.
            </p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Next steps
            </label>
            <p class="text-sm text-gray-600 dark:text-dark-400">
                To enable <strong>advanced tracking</strong> or for further instructions, check the published config or the setup guide in the <a class="simpleLink underline" href="/docs" target="_blank">documentation</a>.
            </p>
        </div>
    </div>
</template>
