<script setup>
import {Link} from "@inertiajs/vue3";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import LanguageSwitcher from "@/Components/LanguageSwitcher.vue";
import {useTrans} from '@/Composables/useTrans.js';

const trans = useTrans();

let appName = import.meta.env.VITE_APP_NAME;

const linkCategories = [
    {
        "header": () => trans("Why :appName?", { appName }),
        "links": [
            {"title": () => trans("Powerful KPIs"), "url": "/docs/introduction.html#kpis"},
            {"title": () => trans("Simple Setup"), "url": "/docs/installation.html"},
            {"title": () => trans("Server-side Tracking"), "url": "/docs/introduction.html#server-side-vs-client-side-tracking"},
            {"title": () => trans("Data Import"), "url": "/docs/how-to-import-existing-data.html"},
            {"title": () => trans("Live demo"), "url": "https://demo.simplestats.io/login"},
            {"title": () => trans("API"), "url": "/docs/ingest-api.html"},
        ],
    },
    {
        "header": () => trans("Community"),
        "links": [
            {"title": "Twitter", "url": "https://x.com/SimpleStats_io"},
            {"title": "GitHub", "url": "https://github.com/simplestats-io"},
            {"title": "Laravel News", "url": "https://laravel-news.com/partner/simplestats"},
            {"title": "Product Hunt", "url": "https://www.producthunt.com/products/simplestats?utm_source=badge-featured&utm_medium=badge#simplestats"},
            {"title": "Reddit", "url": "https://www.reddit.com/r/laravel/comments/1im7n44/release_simplestats_40_a_lightweight_analytics/"},
            {"title": "Blog", "url": "/blog"},
            {"title": "Changelog", "url": "/changelog"},
        ],
    },
    {
        "header": () => trans("Company"),
        "links": [
            {"title": () => trans("Status"), "url": "https://status.simplestats.io"},
            {"title": () => trans("Help & Support"), "url": "https://tawk.to/chat/6601f04ea0c6737bd124880f/1hprp5d8g"},
            {"title": () => trans("Documentation"), "url": "/docs"},
            {"title": () => trans("Imprint"), "url": "/imprint"},
            {"title": () => trans("Privacy"), "url": "/privacy-policy"},
            {"title": () => trans("Terms"), "url": "/terms-of-service"},
            {"title": () => trans("DPA"), "url": "/dpa"},
            {"title": () => trans("Compliance"), "url": "/compliance"},
            {"title": () => trans("Security"), "url": "/security"},
            {"title": () => trans("Trademark"), "url": "/trademark"},
        ],
    },
];
</script>

<template>
    <section class="pb-20 flex justify-center px-4 bg-gray-50 dark:bg-dark-800 text-gray-60 border-t border-gray-200 dark:border-dark-700">
        <div class="hidden grid-cols-1 grid-cols-2 grid-cols-3 grid-cols-4"></div>
        <div class="w-full max-w-screen-xl">
            <div class="container py-12 px-4 sm:px-6 lg:py-16 lg:px-8">
                <div class="xl:grid xl:grid-cols-[1fr_2fr] xl:gap-8">
                    <div class="my-8 xl:my-0">
                        <Link href="/" :aria-label="$t(':appName homepage', { appName })">
                            <ApplicationLogo />
                        </Link>
                        <div class="mt-4 text-base leading-6">
                            {{ $t('Based and hosted in the EU') }} <span class="text-lg">🇪🇺</span>
                        </div>
                        <div class="mt-4">
                            <a href="/compliance" target="_blank" class="inline-block">
                                <img class="rounded-xl shadow-md border border-gray-300" src="/images/gdpr.png" loading="lazy" alt="GDPR compliant" width="192" height="66">
                            </a>
                        </div>
                        <div class="mt-6">
                            <LanguageSwitcher />
                        </div>
                    </div>
                    <div class="grid gap-8 grid-cols-1" :class="`md:grid-cols-${linkCategories.length}`">
                        <div v-for="category in linkCategories">
                            <div class="leading-5 font-semibold tracking-wider uppercase">
                                {{ typeof category.header === 'function' ? category.header() : category.header }}
                            </div>
                            <ul class="mt-4">
                                <li class="mt-4" v-for="link in category.links">
                                    <a :href="link.url" target="_blank" rel="noreferrer" class="text-sm leading-6 hover:underline">{{ typeof link.title === 'function' ? link.title() : link.title }}</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
