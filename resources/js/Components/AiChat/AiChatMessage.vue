<script setup>
import {computed} from 'vue';
import {router} from '@inertiajs/vue3';
import {renderSimpleMarkdown} from '@/Helpers/simpleMarkdown.js';
import AiChatActionCard from '@/Components/AiChat/AiChatActionCard.vue';

const props = defineProps({
    message: Object,
});

const renderedContent = computed(() => renderSimpleMarkdown(props.message.content));

// Same-origin pages that are not part of the Inertia SPA.
const NON_INERTIA_PREFIXES = ['/docs', '/billing', '/blog'];

const scrollToAnchor = (hash) => {
    requestAnimationFrame(() => document.getElementById(hash.slice(1))?.scrollIntoView({behavior: 'smooth'}));
};

// Navigate in-app links via Inertia instead of a full page load, so the chat
// panel (persistent layout) stays open while the user is taken to the page.
const onContentClick = (event) => {
    const link = event.target.closest('a');

    if (!link || link.target === '_blank') {
        return;
    }

    const url = new URL(link.href, window.location.origin);

    if (url.origin !== window.location.origin || NON_INERTIA_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
        return;
    }

    event.preventDefault();

    if (url.pathname === window.location.pathname && url.hash) {
        scrollToAnchor(url.hash);

        return;
    }

    router.visit(url.pathname + url.search, {
        onSuccess: () => url.hash && scrollToAnchor(url.hash),
    });
};
</script>

<template>
    <div v-if="message.role === 'user'" class="flex justify-end">
        <div class="max-w-[85%] rounded-lg rounded-br-sm bg-indigo-100 dark:bg-indigo-500 px-3 py-2 text-sm text-gray-900 dark:text-white whitespace-pre-wrap break-words">{{ message.content }}</div>
    </div>
    <div v-else class="flex flex-col gap-3">
        <div v-if="message.content" @click="onContentClick" class="max-w-full text-sm text-gray-700 dark:text-gray-200 space-y-2 break-words" v-html="renderedContent"></div>
        <AiChatActionCard v-for="action in message.actions ?? []" :key="action.id" :action="action" />
    </div>
</template>
