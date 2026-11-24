<script setup>
import {computed, nextTick, onMounted, ref, watch} from 'vue';
import {useAiChat} from '@/Composables/useAiChat.js';
import {copyToClipboard} from '@/Helpers/ClipboardHelpers.js';
import AiChatMessage from '@/Components/AiChat/AiChatMessage.vue';
import Dropdown from '@/Components/Dropdown.vue';
import StarsIcon from 'bootstrap-icons/icons/stars.svg?component';
import PlusIcon from 'bootstrap-icons/icons/plus-lg.svg?component';
import CloseIcon from 'bootstrap-icons/icons/x-lg.svg?component';
import SendIcon from 'bootstrap-icons/icons/send.svg?component';
import StopIcon from 'bootstrap-icons/icons/stop-circle.svg?component';
import ChevronDownIcon from 'bootstrap-icons/icons/chevron-down.svg?component';
import ClipboardIcon from 'bootstrap-icons/icons/clipboard.svg?component';
import ClipboardCheckIcon from 'bootstrap-icons/icons/clipboard-check.svg?component';
import ClaudeIcon from '@/Components/CustomIcons/ClaudeIcon.vue';
import CodexIcon from '@/Components/CustomIcons/CodexIcon.vue';
import CursorIcon from '@/Components/CustomIcons/CursorIcon.vue';
import OpenCodeIcon from '@/Components/CustomIcons/OpenCodeIcon.vue';

const aiChat = useAiChat();

const currentTitle = computed(() => {
    const active = aiChat.conversations.value.find((conversation) => conversation.id === aiChat.conversationId.value);

    if (active) {
        return active.title;
    }

    return aiChat.messages.value.find((message) => message.role === 'user')?.content ?? 'Ask AI';
});

const placeholder = computed(() => `Ask about ${aiChat.contextProjectName.value ?? 'all projects'}…`);

const input = ref('');
const messageList = ref(null);
const textarea = ref(null);

const exampleQuestions = [
    'How many registrations did I get last month?',
    'Which referrer brings the most revenue?',
    'How did my conversion rate develop this month?',
    'How do I set up payment tracking?',
    'Help me set up a campaign for my next newsletter',
    'Create a new project',
    'Help me invite a team member',
];

const appName = import.meta.env.VITE_APP_NAME;
const agentSetupCopied = ref(false);

// The origin doubles as the MCP endpoint host for self-hosted installs, whose /docs links redirect to the public docs.
const copyAgentSetupPrompt = async () => {
    const origin = window.location.origin;

    const copied = await copyToClipboard(
        `Fetch and follow the instructions from ${origin}/docs/agent-setup.md to connect the ${appName} MCP server to this agent. `
        + `The MCP endpoint is ${origin}/mcp.`,
    );

    if (!copied) {
        return;
    }

    agentSetupCopied.value = true;
    setTimeout(() => agentSetupCopied.value = false, 2000);
};

const scrollToBottom = () => {
    nextTick(() => {
        messageList.value?.scrollTo({top: messageList.value.scrollHeight});
    });
};

watch(() => aiChat.messages.value.map((message) => message.content.length).join(), scrollToBottom);

// The panel only mounts when the chat opens (v-if in AppLayout), so this
// focuses the input for every trigger (StatsFilter pill, global button).
onMounted(() => {
    scrollToBottom();
    nextTick(() => textarea.value?.focus());
});

const newConversation = () => {
    aiChat.newConversation();
    nextTick(() => textarea.value?.focus());
};

const resizeTextarea = () => {
    const el = textarea.value;

    if (el) {
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
    }
};

const send = (text = null) => {
    const message = text ?? input.value;

    if (!message.trim() || aiChat.isStreaming.value) {
        return;
    }

    input.value = '';
    nextTick(resizeTextarea);
    aiChat.sendMessage(message);
};

const onKeydown = (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send();
    }
};
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex items-center justify-between gap-2 border-b dark:border-dark-700 px-4 py-3">
            <div class="flex min-w-0 items-center gap-2 font-semibold text-gray-900 dark:text-white">
                <StarsIcon class="size-5 shrink-0 text-indigo-500" />
                <Dropdown align="left" width="48" :content-classes="['py-1', 'bg-white dark:bg-dark-700', 'max-h-80', 'overflow-y-auto']">
                    <template #trigger>
                        <button type="button" aria-label="Switch conversation" v-tooltip="'Conversations'" @click="aiChat.loadConversations()" class="flex min-w-0 max-w-52 items-center gap-1.5 rounded px-1.5 py-1 hover:bg-gray-100 dark:hover:bg-dark-700">
                            <span class="truncate">{{ currentTitle }}</span>
                            <ChevronDownIcon class="size-3 shrink-0 text-gray-400" />
                        </button>
                    </template>
                    <template #content>
                        <div v-if="!aiChat.conversations.value.length" class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
                            No conversations yet
                        </div>
                        <button
                            v-for="conversation in aiChat.conversations.value"
                            :key="conversation.id"
                            type="button"
                            @click="aiChat.switchConversation(conversation.id)"
                            class="block w-full truncate px-4 py-2 text-start text-sm font-normal hover:bg-gray-100 dark:hover:bg-dark-600"
                            :class="conversation.id === aiChat.conversationId.value ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-700 dark:text-gray-300'"
                        >
                            {{ conversation.title }}
                        </button>
                    </template>
                </Dropdown>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" aria-label="New conversation" v-tooltip="'New conversation'" @click="newConversation()" class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded hover:bg-gray-100 dark:hover:bg-dark-700">
                    <PlusIcon class="size-5" />
                </button>
                <button type="button" aria-label="Close chat" @click="aiChat.setOpen(false)" class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded hover:bg-gray-100 dark:hover:bg-dark-700">
                    <CloseIcon class="size-5" />
                </button>
            </div>
        </div>

        <div v-if="!aiChat.messages.value.length" class="border-b dark:border-dark-700 px-4 py-3">
            <button
                type="button"
                @click="copyAgentSetupPrompt()"
                class="group flex w-full items-center gap-3 rounded-lg border border-indigo-200 dark:border-indigo-900 bg-indigo-50 dark:bg-indigo-950/40 px-3 py-2.5 text-start hover:border-indigo-400 dark:hover:border-indigo-600"
            >
                <component :is="agentSetupCopied ? ClipboardCheckIcon : ClipboardIcon" class="size-5 shrink-0 text-indigo-500" />
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">
                        {{ agentSetupCopied ? 'Copied, paste it into your agent' : 'Use it in your own AI agent' }}
                    </span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">Copy the MCP setup prompt</span>
                </span>
                <span class="flex shrink-0 items-center gap-1.5 text-gray-700 dark:text-gray-300">
                    <ClaudeIcon class="size-4" />
                    <CodexIcon class="size-4" />
                    <CursorIcon class="size-4" />
                    <OpenCodeIcon class="h-4 w-auto" />
                </span>
            </button>
        </div>

        <div ref="messageList" class="flex-1 space-y-4 overflow-y-auto px-4 py-4">
            <div v-if="!aiChat.messages.value.length" class="flex h-full flex-col justify-center gap-3 text-center">
                <StarsIcon class="mx-auto size-8 text-indigo-400" />
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Ask anything about your stats. For example:
                </p>
                <div class="flex flex-col gap-2">
                    <button
                        v-for="question in exampleQuestions"
                        :key="question"
                        type="button"
                        @click="send(question)"
                        class="rounded-lg border dark:border-dark-700 bg-gray-50 dark:bg-dark-900 px-3 py-2 text-sm text-gray-600 dark:text-gray-300 hover:border-indigo-300 hover:text-indigo-600 dark:hover:text-indigo-400"
                    >
                        {{ question }}
                    </button>
                </div>
            </div>

            <AiChatMessage v-for="(message, index) in aiChat.messages.value" :key="index" :message="message" />

            <div v-if="aiChat.toolActivity.value" class="flex items-center gap-2 text-sm text-gray-400 dark:text-gray-500 animate-pulse">
                <StarsIcon class="size-4" />
                {{ aiChat.toolActivity.value }}
            </div>
            <div v-else-if="aiChat.isStreaming.value && !aiChat.messages.value.at(-1)?.content" class="flex items-center gap-2 text-sm text-gray-400 dark:text-gray-500 animate-pulse">
                <StarsIcon class="size-4" />
                Thinking…
            </div>

            <div v-if="aiChat.error.value" class="rounded-lg border border-red-200 dark:border-red-900 bg-red-50 dark:bg-red-950 px-3 py-2 text-sm text-red-700 dark:text-red-300">
                {{ aiChat.error.value }}
                <button type="button" @click="aiChat.retry()" class="ml-1 font-semibold underline hover:no-underline">Retry</button>
            </div>
        </div>

        <div class="border-t dark:border-dark-700 p-3">
            <div class="flex items-center gap-2 rounded-lg border dark:border-dark-700 bg-gray-50 dark:bg-dark-900 px-3 py-2 focus-within:border-indigo-400 dark:focus-within:border-indigo-500">
                <textarea
                    ref="textarea"
                    v-model="input"
                    rows="1"
                    :placeholder="placeholder"
                    class="max-h-40 flex-1 resize-none border-0 bg-transparent p-0 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-0"
                    @input="resizeTextarea"
                    @keydown="onKeydown"
                ></textarea>
                <button
                    v-if="aiChat.isStreaming.value"
                    type="button"
                    aria-label="Stop response"
                    v-tooltip="'Stop'"
                    @click="aiChat.abort()"
                    class="p-1 text-gray-400 hover:text-red-500"
                >
                    <StopIcon class="size-5" />
                </button>
                <button
                    v-else
                    type="button"
                    aria-label="Send message"
                    :disabled="!input.trim()"
                    @click="send()"
                    class="p-1 text-indigo-500 hover:text-indigo-600 disabled:text-gray-300 dark:disabled:text-dark-600"
                >
                    <SendIcon class="size-5" />
                </button>
            </div>
        </div>
    </div>
</template>
