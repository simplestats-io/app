<script setup>
import ChartBarIcon from "bootstrap-icons/icons/bar-chart.svg?component";
import CodeBracketIcon from "bootstrap-icons/icons/code-slash.svg?component";
import GiftIcon from "bootstrap-icons/icons/gift.svg?component";
import LightBulbIcon from "bootstrap-icons/icons/lightbulb.svg?component";
import MapPinIcon from "bootstrap-icons/icons/geo-alt.svg?component";
import PresentationChartBarIcon from "bootstrap-icons/icons/graph-up-arrow.svg?component";
import RocketLaunchIcon from "bootstrap-icons/icons/rocket-takeoff.svg?component";
import ShieldCheckIcon from "bootstrap-icons/icons/shield-check.svg?component";
import UserGroupIcon from "bootstrap-icons/icons/people.svg?component";
import ArrowRepeatIcon from "bootstrap-icons/icons/arrow-repeat.svg?component";
import CashCoinIcon from "bootstrap-icons/icons/cash-coin.svg?component";
import BarChartStepsIcon from "bootstrap-icons/icons/bar-chart-steps.svg?component";
import CurrencyExchangeIcon from "bootstrap-icons/icons/currency-exchange.svg?component";
import StarsIcon from "bootstrap-icons/icons/stars.svg?component";
import GithubIcon from "bootstrap-icons/icons/github.svg?component";

import SectionHeader from "@/Pages/Home/Partials/SectionHeader.vue";

import {useTrans} from '@/Composables/useTrans.js';

const trans = useTrans();

const f = {
    different: {
        icon: LightBulbIcon,
        title: () => trans("Different"),
        descr: () => trans("Traditional client-side analytic tools are only about 70% accurate because of ad blockers and they often only show you data like visits and views. That's where server-side analytics like SimpleStats shines; it gives you superpowers without the integration hustle!"),
    },
    simple: {
        icon: RocketLaunchIcon,
        title: () => trans("Simple"),
        descr: () => trans("Install the Composer package, add your token to the .env file, and you're ready to analyze your campaigns and users."),
    },
    reporting: {
        icon: PresentationChartBarIcon,
        title: () => trans("Reporting"),
        descr: () => trans("The tool provides you with a powerful reporting system that allows you to create custom reports, filter them by location, device, and UTM parameters. The frequency of the reports can be saved and scheduled individually, so you never miss important updates!"),
    },
    api: {
        icon: CodeBracketIcon,
        title: () => trans("API"),
        descr: () => trans("The REST API works with any stack, not just Laravel. Trigger the requests manually and track any custom project."),
    },
    utms: {
        icon: MapPinIcon,
        title: () => trans("UTMs and Referrers"),
        descr: () => trans("UTMs and Referrers are collected automatically and linked to your users and payments, so you see the real ROI and best-performing channel of every campaign."),
    },
    kpis: {
        icon: ChartBarIcon,
        title: () => trans("KPIs"),
        descr: () => trans("Unique Visitors, Conversion Rate, Active Users, ARPU, MRR, Revenue and many more KPIs across the whole funnel, all filterable by date, location, device and channel."),
    },
    subscriptions: {
        icon: ArrowRepeatIcon,
        title: () => trans("Subscriptions"),
        descr: () => trans("MRR, ARR, churn and retention out of the box, attributed to the channel that won each customer."),
    },
    profit: {
        icon: CashCoinIcon,
        title: () => trans("Campaign profit"),
        descr: () => trans("Add your ad spend per campaign and get ROAS, CAC and LTV:CAC, so you see which channels make money after costs."),
    },
    retention: {
        icon: BarChartStepsIcon,
        title: () => trans("Retention"),
        descr: () => trans("Cohort retention, stickiness and active users, per channel. See not just who signs up, but who comes back and keeps paying."),
    },
    currency: {
        icon: CurrencyExchangeIcon,
        title: () => trans("Multi-currency"),
        descr: () => trans("Selling in several currencies? Foreign payments are auto-converted at daily exchange rates into your team's currency, so every revenue metric stays comparable."),
    },
    askAi: {
        icon: StarsIcon,
        title: () => trans("Ask AI"),
        descr: () => trans("Ask questions about your stats in plain language and get answers backed by your real numbers. Chat right in your dashboard, or connect Claude, Cursor and other AI tools to your data via the built-in MCP server."),
    },
    privacy: {
        icon: ShieldCheckIcon,
        title: () => trans("Privacy"),
        descr: () => trans("We are fully committed to GDPR compliance! No cookies are required or stored, ensuring your data remains confidential. You can rely on SimpleStats to respect your privacy and guarantee that your data will never be shared."),
    },
    teams: {
        icon: UserGroupIcon,
        title: () => trans("Collaborate"),
        descr: () => trans("Create a team, invite co-workers to your projects, and assign permissions. Each team is fully separated by tenancy."),
    },
    free: {
        icon: GiftIcon,
        title: () => trans("Free"),
        descr: () => trans("There will always be a free plan, with no credit card required to sign up. Subscribe to support us as your business grows."),
    },
};
</script>

<template>
    <section class="flex justify-center">
        <div class="w-full max-w-screen-xl px-6">
            <SectionHeader id="features" :eyebrow="$t('Features')">
                {{ $t('What makes us') }}&nbsp;<span class="text-indigo-500 underline">{{ $t('unique') }}</span>
            </SectionHeader>
            <ul role="list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 lg:gap-6">
                <!-- Hero card: Different -->
                <li class="relative overflow-hidden lg:col-span-3 lg:row-span-2 rounded-3xl bg-white dark:bg-dark-800 p-8 lg:p-12 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="absolute -top-32 -right-32 size-96 rounded-full bg-indigo-500/15 blur-3xl pointer-events-none"></div>
                    <div class="relative flex flex-col gap-6">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center justify-center size-12 rounded-xl bg-indigo-600 text-white">
                                <component :is="f.different.icon" class="size-6" />
                            </div>
                            <span class="text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">{{ $t('Server-side') }}</span>
                        </div>
                        <h3 class="text-3xl lg:text-4xl font-bold tracking-tight text-gray-900 dark:text-white max-w-xl">
                            {{ f.different.title() }}
                        </h3>
                        <div class="flex items-end gap-8 tabular-nums">
                            <div>
                                <div class="text-5xl lg:text-6xl font-extrabold text-indigo-600 dark:text-indigo-400">100%</div>
                                <div class="mt-1 text-sm font-medium text-gray-600 dark:text-dark-400">{{ $t('accurate') }}</div>
                            </div>
                            <div class="h-16 w-px bg-gray-950/10 dark:bg-white/10"></div>
                            <div>
                                <div class="text-5xl lg:text-6xl font-extrabold text-gray-500 dark:text-dark-400 line-through decoration-2">70%</div>
                                <div class="mt-1 text-sm font-medium text-gray-600 dark:text-dark-400">{{ $t('client-side') }}</div>
                            </div>
                        </div>
                        <p class="text-base lg:text-lg leading-relaxed text-gray-600 dark:text-dark-400 max-w-2xl">
                            {{ f.different.descr() }}
                        </p>
                    </div>
                </li>

                <!-- Tall card: Privacy -->
                <li class="lg:row-span-2 flex flex-col gap-4 rounded-3xl bg-indigo-600 p-8 text-white shadow-lg shadow-indigo-600/20">
                    <div class="flex items-center justify-center size-12 rounded-xl bg-white/15 backdrop-blur">
                        <component :is="f.privacy.icon" class="size-6" />
                    </div>
                    <h3 class="text-2xl font-bold tracking-tight">{{ f.privacy.title() }}</h3>
                    <div class="text-sm font-semibold uppercase tracking-widest text-indigo-50">{{ $t('GDPR, CCPA & PECR compliant') }}</div>
                    <p class="text-base leading-relaxed text-indigo-100">{{ f.privacy.descr() }}</p>
                    <a href="/compliance" target="_blank" class="inline-flex items-center gap-2 text-sm font-semibold text-white hover:text-indigo-200">
                        {{ $t('Compliance & security') }} <span aria-hidden="true">&rarr;</span>
                    </a>
                    <div class="mt-auto border-t border-white/15 pt-4">
                        <div class="text-sm font-semibold uppercase tracking-widest text-indigo-50">{{ $t('Open source') }}</div>
                        <p class="mt-2 text-sm leading-relaxed text-indigo-100">{{ $t('The code is public, so you can verify exactly what we collect.') }}</p>
                        <a href="https://github.com/simplestats-io/app" target="_blank" class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-white hover:text-indigo-200">
                            <GithubIcon class="size-4" />
                            {{ $t('View on GitHub') }}
                        </a>
                    </div>
                </li>

                <!-- Row: Reporting wide + UTMs -->
                <li class="lg:col-span-2 flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.reporting.icon" class="size-6" />
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">{{ f.reporting.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.reporting.descr() }}</p>
                </li>
                <li class="flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.utms.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.utms.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.utms.descr() }}</p>
                </li>

                <!-- Bottom rows: utility fillers wrap two wide "depth" rows -->
                <li class="flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.simple.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.simple.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.simple.descr() }}</p>
                </li>
                <li class="lg:col-span-2 flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.subscriptions.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.subscriptions.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.subscriptions.descr() }}</p>
                </li>
                <li class="lg:col-span-2 flex flex-col gap-3 rounded-3xl bg-indigo-600 p-7 text-white shadow-lg shadow-indigo-600/20">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-white/15 backdrop-blur">
                        <component :is="f.profit.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold">{{ f.profit.title() }}</h3>
                    <p class="text-sm leading-relaxed text-indigo-100">{{ f.profit.descr() }}</p>
                </li>
                <li class="lg:col-span-2 flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.retention.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.retention.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.retention.descr() }}</p>
                </li>
                <li class="lg:col-span-2 flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.kpis.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.kpis.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.kpis.descr() }}</p>
                </li>
                <!-- Full-width banner: Ask AI -->
                <li class="sm:col-span-2 lg:col-span-4 relative overflow-hidden rounded-3xl bg-indigo-600 p-8 lg:p-10 text-white shadow-lg shadow-indigo-600/20">
                    <div class="absolute -bottom-32 -left-32 size-96 rounded-full bg-white/10 blur-3xl pointer-events-none"></div>
                    <div class="relative flex flex-col lg:flex-row lg:items-center gap-6 lg:gap-12">
                        <div class="flex items-center gap-4 shrink-0">
                            <div class="flex items-center justify-center size-12 rounded-xl bg-white/15 backdrop-blur">
                                <component :is="f.askAi.icon" class="size-6" />
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold tracking-tight">{{ f.askAi.title() }}</h3>
                                <div class="text-sm font-semibold uppercase tracking-widest text-indigo-50">{{ $t('Chat & MCP') }}</div>
                            </div>
                        </div>
                        <p class="text-base leading-relaxed text-indigo-100 max-w-3xl">{{ f.askAi.descr() }}</p>
                    </div>
                </li>

                <li class="flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.api.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.api.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.api.descr() }}</p>
                </li>
                <li class="flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.teams.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.teams.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.teams.descr() }}</p>
                </li>
                <li class="flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.free.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.free.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.free.descr() }}</p>
                </li>
                <li class="flex flex-col gap-3 rounded-3xl bg-white dark:bg-dark-800 p-7 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex items-center justify-center size-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <component :is="f.currency.icon" class="size-6" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ f.currency.title() }}</h3>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-dark-400">{{ f.currency.descr() }}</p>
                </li>
            </ul>
        </div>
    </section>
</template>
