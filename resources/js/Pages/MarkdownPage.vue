<script setup>
import {Head, Link} from '@inertiajs/vue3';
import ApplicationLogo from "@/Components/ApplicationLogo.vue";

defineProps({
    content: String,
    meta: Object,
});
</script>

<template>
    <Head :title="meta.title" />

    <div class="font-sans text-gray-900 dark:text-dark-100 antialiased">
        <div class="pt-4 bg-gray-100 dark:bg-dark-900">
            <div class="min-h-screen flex flex-col items-center pt-6 sm:pt-0">
                <Link href="/" aria-label="Home">
                    <ApplicationLogo />
                </Link>

                <div class="w-full sm:max-w-2xl mt-6 p-6 bg-white dark:bg-dark-800 shadow-md overflow-hidden sm:rounded-lg prose dark:prose-invert" v-html="content" />
            </div>
        </div>
    </div>
</template>
