<script setup>
import CodeSlashIcon from "bootstrap-icons/icons/code-slash.svg?component";
import StripeLogo from "@/Pages/Home/Partials/Integration/Logos/stripe.svg?component";
import PaddleLogo from "@/Pages/Home/Partials/Integration/Logos/paddle.svg?component";
import LemonSqueezyLogo from "@/Pages/Home/Partials/Integration/Logos/lemon-squeezy.svg?component";
import MollieLogo from "@/Pages/Home/Partials/Integration/Logos/mollie.svg?component";
import PolarLogo from "@/Pages/Home/Partials/Integration/Logos/polar.svg?component";

// Mollie's logo is a wordmark, so it replaces the name instead of standing next to it.
const providers = [
    { name: 'Stripe', via: 'via Cashier', logo: StripeLogo, anchor: 'cashier-stripe' },
    { name: 'Paddle', via: 'via Cashier', logo: PaddleLogo, anchor: 'cashier-paddle' },
    { name: 'Lemon Squeezy', via: null, logo: LemonSqueezyLogo, anchor: 'lemon-squeezy' },
    { name: 'Mollie', via: 'via Cashier', logo: MollieLogo, anchor: 'cashier-mollie', wordmark: true },
    { name: 'Polar', via: null, logo: PolarLogo, anchor: 'polar' },
];

const tile = 'flex items-center gap-2 rounded-xl border border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-800 px-3 py-2 text-gray-700 dark:text-dark-300 transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:border-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400 active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500';
</script>

<template>
    <div class="mt-4">
        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $t('Works with your payment provider') }}</p>
        <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
            <a
                v-for="provider in providers"
                :key="provider.anchor"
                :href="`/docs/payment-providers.html#${provider.anchor}`"
                target="_blank"
                :class="tile"
            >
                <component
                    :is="provider.logo"
                    :class="provider.wordmark ? 'h-3.5 w-auto shrink-0' : 'size-5 shrink-0'"
                    :role="provider.wordmark ? 'img' : null"
                    :aria-label="provider.wordmark ? provider.name : null"
                    :aria-hidden="provider.wordmark ? null : 'true'"
                />
                <span class="flex flex-col leading-tight">
                    <span v-if="!provider.wordmark" class="text-sm font-semibold">{{ provider.name }}</span>
                    <span v-if="provider.via" class="text-xs text-gray-500 dark:text-dark-400">{{ $t(provider.via) }}</span>
                </span>
            </a>
            <a href="/docs/how-to-track-a-new-payment.html" target="_blank" :class="tile">
                <CodeSlashIcon class="size-5 shrink-0" aria-hidden="true" />
                <span class="flex flex-col leading-tight">
                    <span class="text-sm font-semibold">{{ $t('Any other') }}</span>
                    <span class="text-xs text-gray-500 dark:text-dark-400">{{ $t('your own model') }}</span>
                </span>
            </a>
        </div>
    </div>
</template>
