<script setup>
import {computed, ref} from "vue";
import CopyableCode from "@/Components/Project/CopyableCode.vue";
import {copyToClipboard} from "@/Helpers/ClipboardHelpers.js";
import ChevronDownIcon from "bootstrap-icons/icons/chevron-down.svg?component";

const props = defineProps({
    apiToken: {type: String, default: null},
    domain: {type: String, default: null},
    showVariants: {type: Boolean, default: false},
    tracking: {type: Array, default: () => []},
});

const appName = import.meta.env.VITE_APP_NAME;
const isSelfHosted = import.meta.env.VITE_APP_TYPE !== 'saas';

const variants = [
    {value: 'marketing', name: 'Marketing site', descr: 'Webflow, Framer, WordPress, Next.js, ...'},
    {value: 'headless', name: 'Separate frontend app', descr: 'Next.js, Nuxt or SPA on your Laravel API'},
];

// Site builder subdomains can never be rendered by the Laravel app itself.
const isSiteBuilderDomain = /\.(webflow\.io|framer\.(website|app|ai)|wpcomstaging\.com|wordpress\.com)$/i.test(props.domain ?? '');

const variantsOpen = ref(props.showVariants || isSiteBuilderDomain);
const variant = ref(isSiteBuilderDomain ? 'marketing' : null);

const frontend = computed(() => (variantsOpen.value && variant.value) || 'laravel');

const toggleVariants = () => {
    variantsOpen.value = ! variantsOpen.value;
    variant.value ??= 'marketing';
};

const mode = ref('manual');
const modes = [
    {value: 'manual', name: 'Manual'},
    {value: 'agent', name: 'With your AI agent'},
];

// Self-hosted installs ship without built docs, so agents always read the public ones.
const docsOrigin = isSelfHosted ? 'https://simplestats.io' : window.location.origin;

const trackingNames = {visitors: 'visitors', users: 'registrations', payments: 'payments', subscription: 'subscriptions'};

const agentPrompt = computed(() => [
    `Install ${appName} in this Laravel app. Fetch and follow the instructions from ${docsOrigin}/docs/agent-install.md.`,
    '',
    ...(props.apiToken ? [`API token: ${props.apiToken}`] : []),
    ...(isSelfHosted ? [`API URL: ${apiUrl}`] : []),
    ...(props.tracking.length ? [`Tracking: ${props.tracking.map((key) => trackingNames[key] ?? key).join(', ')}`] : []),
    ...(props.domain ? [`Domain: ${props.domain}`] : []),
].join('\n'));

const composerInstallCmd = 'composer require simplestats-io/laravel-client';
const publishConfigCmd = 'php artisan vendor:publish --tag="simplestats-client-config"';

const apiUrl = `${window.location.origin}/api/v1`;

const envLines = computed(() => [
    ...(isSelfHosted ? [['SIMPLESTATS_API_URL', apiUrl]] : []),
    ['SIMPLESTATS_API_TOKEN', props.apiToken ?? ''],
    ...(frontend.value !== 'laravel' ? [['SIMPLESTATS_BEACON_ENABLED', 'true']] : []),
]);

const envBlock = computed(() => envLines.value.map(([key, value]) => `${key}=${value}`).join('\n'));

const allStepsCommand = `${composerInstallCmd}\n${publishConfigCmd}`;

const allStepsCopied = ref(false);

const copyAllSteps = async () => {
    allStepsCopied.value = await copyToClipboard(allStepsCommand);
};

const ownDomainsConfig = computed(() => `'own_domains' => [\n    '${props.domain || 'www.your-marketing-site.com'}',\n],`);

// Only a guess: the script must come from the Laravel app, which often runs on an app subdomain.
const appDomainGuess = props.domain && ! isSiteBuilderDomain && ! /^app\./i.test(props.domain)
    ? `app.${props.domain.replace(/^www\./i, '')}`
    : 'your-laravel-app.com';

const beaconScript = `<script src="https://${appDomainGuess}/assets/v.js" defer><\/script>`;

const headlessConfig = computed(() => `'middleware_groups' => ['api'],\n\n'tracking_storage' => 'cache',\n\n'own_domains' => [\n    '${props.domain || 'www.your-frontend.com'}',\n],`);
</script>

<template>
    <div>
        <div role="tablist" aria-label="Installation method" class="mb-5 grid grid-cols-2 gap-1 rounded-lg bg-gray-100 dark:bg-dark-900 p-1 text-sm">
            <button v-for="option in modes" :key="option.value"
                    type="button"
                    role="tab"
                    :aria-selected="mode === option.value"
                    class="rounded-md px-3 py-1.5 font-medium transition"
                    :class="mode === option.value ? 'bg-white dark:bg-dark-700 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-dark-400 hover:text-gray-700 dark:hover:text-dark-200'"
                    @click="mode = option.value"
            >
                {{ option.name }}
            </button>
        </div>

        <div v-if="mode === 'agent'" class="space-y-3">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                Paste this prompt into Claude Code, Cursor, Codex or any coding agent, opened in your Laravel app. It installs the client, picks the setup for your frontend and adds registration and payment tracking. You review the changes before anything is committed.
            </p>
            <CopyableCode :code="agentPrompt" copy-label="Copy prompt">
                <div class="whitespace-pre-wrap break-words">{{ agentPrompt }}</div>
            </CopyableCode>
            <p class="text-xs text-gray-500 dark:text-dark-400">
                The prompt contains your API token, which stays in the agent's chat history. Only paste it into an agent you already trust with your code.
                <template v-if="! apiToken"> Without the token, the agent asks you for it: a team administrator finds it under Projects.</template>
            </p>
        </div>

        <div v-else>
            <div class="-mt-2 mb-3 text-right">
                <button type="button" class="text-xs text-gray-500 dark:text-dark-400 underline hover:text-gray-700 dark:hover:text-dark-200" @click="copyAllSteps">
                    {{ allStepsCopied ? 'Copied' : 'Copy steps 1 and 2 as one command' }}
                </button>
            </div>
            <ol class="space-y-5">
                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400">1</span>
                    <div class="min-w-0 flex-1">
                        <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Install the {{ appName }} client in your Laravel app</p>
                        <CopyableCode :code="composerInstallCmd" copy-label="Copy command" />
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400">2</span>
                    <div class="min-w-0 flex-1">
                        <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Publish the config</p>
                        <CopyableCode :code="publishConfigCmd" copy-label="Copy command" />
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400">3</span>
                    <div class="min-w-0 flex-1">
                        <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Add this to your <code>.env</code></p>
                        <CopyableCode v-if="apiToken" :code="envBlock" copy-label="Copy environment variables">
                            <div v-for="[key, value] in envLines" :key="key" class="truncate">
                                <span class="text-blue-600 dark:text-blue-500">{{ key }}=</span><span class="text-green-600 dark:text-green-500">{{ value }}</span>
                            </div>
                        </CopyableCode>
                        <p v-else class="text-sm text-gray-600 dark:text-dark-400">
                            Ask a team administrator for the project's API token.
                        </p>
                    </div>
                </li>
            </ol>

            <div class="mt-5 border-t border-gray-200 dark:border-dark-700 pt-4">
                <button type="button"
                        class="flex w-full items-center justify-between gap-2 text-left text-sm text-gray-600 dark:text-dark-400 hover:text-gray-900 dark:hover:text-dark-200"
                        :aria-expanded="variantsOpen"
                        @click="toggleVariants"
                >
                    <span>Landing page not built with Laravel? (Webflow, Next.js, SPA)</span>
                    <ChevronDownIcon class="size-4 shrink-0 transition-transform" :class="{'rotate-180': variantsOpen}" />
                </button>
                <div v-if="variantsOpen" role="radiogroup" aria-label="Frontend setup" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <label v-for="option in variants" :key="option.value"
                           class="flex items-start gap-2 cursor-pointer select-none rounded-lg border px-3 py-2 transition hover:border-indigo-300 dark:hover:border-gray-400"
                           :class="variant === option.value ? 'border-indigo-400 ring-1 ring-indigo-400' : 'border-gray-200 dark:border-dark-600'"
                    >
                        <input v-model="variant"
                               type="radio"
                               name="frontend-variant"
                               :value="option.value"
                               class="mt-0.5 text-indigo-500 bg-gray-100 border-gray-300 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:checked:bg-indigo-500 focus:ring-2 dark:focus:ring-indigo-500 dark:ring-offset-gray-800"
                        />
                        <span>
                            <span class="block text-sm font-medium">{{ option.name }}</span>
                            <span class="block text-xs text-gray-500 dark:text-dark-400">{{ option.descr }}</span>
                        </span>
                    </label>
                </div>
                <ol v-if="frontend !== 'laravel'" class="mt-5">
                    <li class="flex gap-3">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400">4</span>
                        <div class="min-w-0 flex-1 space-y-3">
                            <template v-if="frontend === 'marketing'">
                                <div>
                                    <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">List your marketing site in <code>config/simplestats-client.php</code></p>
                                    <CopyableCode :code="ownDomainsConfig" copy-label="Copy config" />
                                </div>
                                <div>
                                    <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Add this script to every page</p>
                                    <CopyableCode :code="beaconScript" copy-label="Copy script" />
                                <p class="mt-1.5 text-xs text-gray-500 dark:text-dark-400">Replace <code>{{ appDomainGuess }}</code> with the domain your Laravel app runs on.</p>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-dark-400">
                                    Site on another domain than your app (e.g. <code>.webflow.io</code>)? Also set <code>'tracking_storage' => 'cache'</code>.
                                    Where to paste the script: <a class="simpleLink underline" href="/docs/frontend-frameworks.html" target="_blank">setup guides per platform</a>.
                                </p>
                            </template>
                            <template v-else>
                                <div>
                                    <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Switch to the headless setup in <code>config/simplestats-client.php</code></p>
                                    <CopyableCode :code="headlessConfig" copy-label="Copy config" />
                                </div>
                                <div>
                                    <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Add this script to your frontend's HTML</p>
                                    <CopyableCode :code="beaconScript" copy-label="Copy script" />
                                    <p class="mt-1.5 text-xs text-gray-500 dark:text-dark-400">Replace <code>{{ appDomainGuess }}</code> with the domain your Laravel app runs on.</p>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-dark-400">
                                    Signup and checkout must call your API from the browser, not from your frontend's server. Details in the <a class="simpleLink underline" href="/docs/headless-stateless-spa.html" target="_blank">headless setup guide</a>.
                                </p>
                            </template>
                        </div>
                    </li>
                </ol>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-dark-400">
                Registrations, payments and advanced options: see the <a class="simpleLink underline" href="/docs" target="_blank">documentation</a>.
            </p>
        </div>
    </div>
</template>
