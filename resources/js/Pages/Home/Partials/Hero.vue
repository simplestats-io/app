<script setup>
import {router, usePage} from "@inertiajs/vue3";
import {useLocale} from "@/Composables/useLocale.js";
import {useTrans} from '@/Composables/useTrans.js';
import {computed} from 'vue';
import {testimonials} from "@/Data/testimonials.js";
// eslint-disable-next-line no-unused-vars -- used by the paused "Can't be blocked" badge below
import BanIcon from "bootstrap-icons/icons/shield-x.svg?component";
import ArrowRightIcon from "bootstrap-icons/icons/arrow-right.svg?component";

const trans = useTrans();

const { localizedPath } = useLocale();
const page = usePage();
const appEnv = import.meta.env.VITE_APP_ENV;

const stats = [
    { term: 'Revenue', description: 'Attribution' },
    { term: '100%', description: 'accurate' },
    { term: 'GDPR', description: 'compliant' },
    { term: '5 min', description: 'setup' },
];

const headlineClass = 'm-auto mb-10 text-5xl font-extrabold tracking-tight leading-none md:text-6xl xl:text-7xl dark:text-white px-6 sm:px-3 max-w-screen-lg';
const subheadClass = 'relative m-auto mb-8 font-light text-gray-500 lg:mb-10 md:text-lg lg:text-xl dark:text-dark-400 px-6 sm:px-3 max-w-screen-md';
const statsClass = 'mb-10 mx-auto max-w-3xl px-6 hidden sm:grid sm:grid-cols-4 gap-y-3 tabular-nums';

// Highlight markup kept as a string so it can live inside the v-html headlines.
const hl = (word) =>
    `<span class="relative inline-block"><span class="absolute inset-x-0 bottom-2 h-3 -skew-x-6 bg-indigo-200/70 dark:bg-indigo-500/30"></span><span class="relative">${word}</span></span>`;

// Hero A/B variants. The active variant is chosen server-side (AbTestHeroVariant
// middleware via a sticky cookie) and shared as the `heroVariant` Inertia prop, so a
// visitor always sees the same hero and we can tag + measure it through to revenue.
// Keys here must match the active set in the middleware. Paused variants stay
// commented out for a later round.
const FALLBACK_VARIANT = 'control';

const variants = {
    control: {
        headline: () => trans('Analytics for :laravel', {
            laravel: hl('Laravel'),
        }),
        subhead: () => trans('Track visitors, registrations, and payments. Discover which channels actually drive revenue, not just traffic. Server-side, GDPR compliant, 100% accurate.'),
    },
    visitor_to_revenue: {
        headline: () => trans('From :visitor to :revenue.', {
            visitor: hl(trans('visitor')),
            revenue: hl(trans('revenue')),
        }),
        subhead: () => trans('See the full picture: every visit, signup and payment, attributed to the channel that drove it. Server-side and ad-blocker proof, built for Laravel.'),
    },
    outcome_rotation: {
        headline: () => trans('Know which channels :break drive your :revenue.', {
            break: '<br class="hidden sm:block" />',
            revenue: hl(trans('revenue')),
        }),
        subhead: () => trans('SimpleStats tracks the full funnel, from first visit to payment, and ties every result back to the channel that caused it. Server-side, GDPR compliant, ad-blocker proof.'),
    },

    // Paused variants (kept for a later A/B round, not in the active rotation):
    // analytics_meets_revenue: {
    //     headline: () => trans('Where :analytics meets :revenue.', {
    //         analytics: hl(trans('analytics')),
    //         revenue: hl(trans('revenue')),
    //     }),
    //     subhead: () => trans('Plausible shows you traffic. Baremetrics shows you MRR. SimpleStats connects them: which channels turn into real, recurring revenue. Server-side, for Laravel.'),
    // },
    // campaigns_make_money: {
    //     headline: () => trans('See which campaigns :break actually :makeMoney.', {
    //         break: '<br class="hidden sm:block" />',
    //         makeMoney: hl(trans('make money')),
    //     }),
    //     subhead: () => trans('SimpleStats already knows the revenue behind every channel. Add your ad spend and see real ROAS, CAC and profit, after churn. Server-side, built for Laravel.'),
    // },
};

const current = computed(() => variants[page.props.heroVariant] ?? variants[FALLBACK_VARIANT]);

function trackGetStartedClick() {
    window.axios.post(route('tracking.get-started-click')).finally(() => {
        router.visit(localizedPath('/register'));
    });
}
</script>

<template>
    <section class="animationElement text-center pt-24">
        <h1 :class="headlineClass">
            <span v-html="current.headline()"></span>
        </h1>

        <div :class="subheadClass">
            <span v-html="current.subhead()"></span>
            <!-- "Can't be blocked" badge (paused):
            <a href="/docs/introduction.html#server-side-vs-client-side-tracking" target="_blank" class="hidden xl:flex absolute right-0 top-20 -mr-64 -mt-10 rotate-[18deg] hover:rotate-[12deg] transition-transform">
                <div class="rounded-2xl bg-white dark:bg-dark-800 ring-1 ring-gray-950/10 dark:ring-white/10 shadow-xl shadow-indigo-600/10 px-4 py-3 flex items-center gap-3">
                    <div class="flex items-center justify-center size-9 rounded-xl bg-indigo-600 text-white">
                        <BanIcon class="size-5" />
                    </div>
                    <div class="text-left">
                        <div class="text-sm font-bold text-gray-900 dark:text-white">{{ $t("Can't be blocked") }}</div>
                        <div class="text-xs text-gray-500 dark:text-dark-400">{{ $t('by ad blockers') }}</div>
                    </div>
                </div>
            </a>
            -->
        </div>

        <dl :class="statsClass">
            <div
                v-for="(stat, index) in stats"
                :key="stat.term"
                class="flex flex-col items-center sm:px-6 border-gray-950/10 dark:border-white/10"
                :class="{ 'sm:border-l': index > 0 }"
            >
                <dt class="text-2xl md:text-3xl font-extrabold text-indigo-600 dark:text-indigo-400">{{ $t(stat.term) }}</dt>
                <dd class="mt-1 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-dark-400">{{ $t(stat.description) }}</dd>
            </div>
        </dl>

        <div class="relative flex flex-col md:flex-row justify-center">
            <div class="pointer-events-none absolute inset-0 mx-auto w-72 h-16 bg-indigo-500/30 blur-3xl"></div>
            <div class="relative">
                <button v-if="$page.props.canRegister" class="mb-4 inline-flex items-center justify-center px-6 py-3 mx-1.5 text-base font-medium text-center text-white rounded-lg bg-indigo-600 hover:bg-indigo-700 shadow-lg shadow-indigo-600/25 focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-900 transition hover:scale-[1.02]" @click="trackGetStartedClick">
                    {{ $t('Get started for free') }}
                    <ArrowRightIcon class="size-5 ml-2 -mr-1" />
                </button>
            </div>
            <div class="relative">
                <a v-if="$page.props.canLogin" href="https://demo.simplestats.io/login" :target="(appEnv === 'demo') ? '_self' : '_blank'" class="mb-4 mx-1.5 inline-flex items-center justify-center px-6 py-3 text-base font-medium text-center text-gray-900 dark:text-white border border-gray-300 rounded-lg bg-white dark:bg-dark-800 hover:bg-gray-100 focus:ring-4 focus:ring-gray-100 dark:border-dark-700 dark:hover:bg-dark-700 dark:focus:ring-gray-800">
                    {{ $t('Live demo') }}
                </a>
            </div>
        </div>

        <div class="mt-10 flex flex-col items-center">
            <div class="flex -space-x-2">
                <a
                    v-for="t in testimonials"
                    :key="t.name"
                    v-tooltip.bottom="{
                        content: `<div class='flex flex-col space-y-2 max-w-72 p-2 text-base text-left text-white'><p class='font-bold text-indigo-300'>${t.name}</p><p class='text-sm text-gray-300'>${t.title}${t.company ? ', ' + t.company : ''}</p><p class='text-sm text-gray-100'>${t.descr}</p></div>`,
                        html: true,
                    }"
                    :href="t.social_link || t.company_link"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="block size-10 shrink-0"
                >
                    <img
                        :src="t.avatar"
                        :alt="t.name"
                        width="40"
                        height="40"
                        decoding="async"
                        class="block size-10 rounded-full object-cover ring-2 ring-white dark:ring-dark-900 transition-all hover:scale-110"
                    />
                </a>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-dark-400">{{ $t('Loved by the Laravel community') }}</p>
        </div>
    </section>
</template>

<style scoped>
.animationElement {
  animation: slideInUp 0.8s ease forwards;
}

/* Transform only: an element that starts at opacity 0 is not an LCP candidate until it
   becomes visible, which pushed the hero text (mobile) and screenshot (desktop) LCP back. */
@keyframes slideInUp {
  from {
    transform: translateY(1rem);
  }
  to {
    transform: translateY(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .animationElement {
    animation: none;
  }
}
</style>
