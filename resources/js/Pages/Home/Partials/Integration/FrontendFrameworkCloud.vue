<script setup>
import { frontendFrameworks, frontendFrameworksDocs } from "@/Pages/Home/Partials/Integration/frontendFrameworks.js";
</script>

<template>
    <div class="mt-4">
        <div class="flex items-baseline justify-between gap-4">
            <p id="frontend-frameworks-label" class="text-sm font-semibold text-gray-900 dark:text-white">{{ $t('Works with any frontend') }}</p>
            <a :href="frontendFrameworksDocs" target="_blank" class="simpleLink text-sm">{{ $t('How it works') }}</a>
        </div>
        <ul aria-labelledby="frontend-frameworks-label" class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
            <li v-for="framework in frontendFrameworks" :key="framework.name">
                <a
                    :href="framework.docs"
                    target="_blank"
                    class="flex items-center gap-2 rounded-xl border border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-800 px-3 py-2 text-gray-700 dark:text-dark-300 transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400 active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                >
                    <component :is="framework.logo" class="size-5 shrink-0" aria-hidden="true" />
                    <span class="text-sm font-semibold leading-tight">{{ framework.name }}</span>
                </a>
            </li>
        </ul>
    </div>
</template>
