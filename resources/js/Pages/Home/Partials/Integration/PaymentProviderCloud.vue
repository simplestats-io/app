<script setup>
import { computed, nextTick, ref } from "vue";
import CodeSlashIcon from "bootstrap-icons/icons/code-slash.svg?component";
import { paymentProviders } from "@/Pages/Home/Partials/Integration/paymentProviders.js";

// null stands for "Any other", the own payment model.
const selected = defineModel({ type: Object, default: null });

const options = [...paymentProviders, null];

const docsUrl = computed(() => selected.value
    ? `/docs/payment-providers.html#${selected.value.anchor}`
    : '/docs/how-to-track-a-new-payment.html#payment-tracking');
const optionButtons = ref([]);

// On narrow screens the snippet sits above the tiles and changes its height, so the page is
// scrolled by that difference to keep the picked tile under the finger.
const select = async (index) => {
    const button = optionButtons.value[index];
    const top = button.getBoundingClientRect().top;
    selected.value = options[index];
    await nextTick();
    window.scrollBy(0, button.getBoundingClientRect().top - top);
    button.focus();
};

const move = (step) => select((options.indexOf(selected.value) + step + options.length) % options.length);

const tile = (active) => [
    'flex items-center gap-2 rounded-xl border bg-white dark:bg-dark-800 px-3 py-2 text-left transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500',
    active
        ? 'border-indigo-500 ring-1 ring-indigo-500 text-indigo-600 dark:text-indigo-400'
        : 'border-gray-200 dark:border-dark-700 text-gray-700 dark:text-dark-300 hover:border-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400',
];
</script>

<template>
    <div class="mt-4">
        <div class="flex items-baseline justify-between gap-4">
            <p id="payment-provider-label" class="text-sm font-semibold text-gray-900 dark:text-white">{{ $t('Pick your payment provider') }}</p>
            <a :href="docsUrl" target="_blank" class="simpleLink text-sm">{{ $t('How it works') }}</a>
        </div>
        <div
            role="radiogroup"
            aria-labelledby="payment-provider-label"
            class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-2"
            @keydown.left.prevent="move(-1)"
            @keydown.up.prevent="move(-1)"
            @keydown.right.prevent="move(1)"
            @keydown.down.prevent="move(1)"
        >
            <button
                v-for="(option, index) in options"
                :key="option?.anchor ?? 'own-model'"
                ref="optionButtons"
                type="button"
                role="radio"
                :aria-checked="selected === option"
                :tabindex="selected === option ? 0 : -1"
                :class="tile(selected === option)"
                @click="select(index)"
            >
                <template v-if="option">
                    <component
                        :is="option.logo"
                        :class="option.wordmark ? 'h-3.5 w-auto shrink-0' : 'size-5 shrink-0'"
                        :role="option.wordmark ? 'img' : null"
                        :aria-label="option.wordmark ? option.name : null"
                        :aria-hidden="option.wordmark ? null : 'true'"
                    />
                    <span class="flex flex-col leading-tight">
                        <span v-if="!option.wordmark" class="text-sm font-semibold">{{ option.name }}</span>
                        <span v-if="option.via" class="text-xs text-gray-500 dark:text-dark-400">{{ $t(option.via) }}</span>
                    </span>
                </template>
                <template v-else>
                    <CodeSlashIcon class="size-5 shrink-0" aria-hidden="true" />
                    <span class="flex flex-col leading-tight">
                        <span class="text-sm font-semibold">{{ $t('Any other') }}</span>
                        <span class="text-xs text-gray-500 dark:text-dark-400">{{ $t('your own model') }}</span>
                    </span>
                </template>
            </button>
        </div>
    </div>
</template>
