<script setup>
import {Head, Link} from '@inertiajs/vue3';
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import {onMounted} from "vue";

defineProps({
    releases: Array,
});

// Without SSR the sections do not exist yet when the browser resolves the hash of a deep link.
onMounted(() => {
    if (window.location.hash) {
        document.getElementById(window.location.hash.slice(1))?.scrollIntoView();
    }
});
</script>

<template>
    <Head title="Changelog">
        <meta head-key="description" name="description" content="New features, fixes and improvements in every release.">
    </Head>

    <div class="font-sans text-gray-900 dark:text-dark-100 antialiased">
        <div class="pt-4 bg-gray-100 dark:bg-dark-900">
            <div class="min-h-screen flex flex-col items-center pt-6 pb-12 sm:pt-0">
                <Link href="/" aria-label="Homepage">
                    <ApplicationLogo />
                </Link>

                <div class="w-full sm:max-w-2xl mt-6 p-6 bg-white dark:bg-dark-800 shadow-md overflow-hidden sm:rounded-lg prose dark:prose-invert">
                    <h1>Changelog</h1>
                    <p>New features, fixes and improvements in every release.</p>

                    <section v-for="release in releases" :key="release.version" :id="release.anchor" class="scroll-mt-6 border-t border-gray-200 dark:border-dark-700 pt-2">
                        <h2 class="flex flex-wrap items-baseline gap-x-3">
                            <a :href="`#${release.anchor}`" class="no-underline hover:underline">{{ release.version }}</a>
                            <span class="text-sm font-normal text-gray-500 dark:text-dark-400">{{ release.date }}</span>
                        </h2>
                        <div v-html="release.html" />
                    </section>
                </div>
            </div>
        </div>
    </div>
</template>
