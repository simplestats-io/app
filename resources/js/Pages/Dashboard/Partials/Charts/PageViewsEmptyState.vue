<script setup>
import {computed} from "vue";
import {usePage} from "@inertiajs/vue3";

defineProps({
    height: {type: Number, default: 140},
    showGuideLink: {type: Boolean, default: false},
});

const page = usePage();
const hasVisitors = computed(() => !! page.props.pageViews?.hasVisitors);
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-1 px-4 text-center text-sm text-gray-500 dark:text-dark-400" :style="{height: `${height}px`}">
        <template v-if="hasVisitors">
            <p>No page views received yet.</p>
            <a v-if="showGuideLink" href="/docs/how-to-track-page-views.html" target="_blank" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                How to track page views
            </a>
        </template>
        <p v-else>Page views show up as soon as your app sends its first visitors.</p>
    </div>
</template>
