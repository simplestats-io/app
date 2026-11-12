<script setup>
import InputError from '@/Components/InputError.vue';
import ProviderIcon from '@/Components/SocialstreamIcons/ProviderIcon.vue';

defineProps({
    prompt: {
        type: String,
        default: 'Or',
    },
    error: {
        type: String,
        default: null,
    },
    providers: {
        type: Array,
    },
    labels: {
        type: Object,
    }
});
</script>

<template>
    <div v-if="providers.length || $slots.default" class="space-y-6 mt-6 mb-2">
        <InputError v-if="error" :message="error" class="text-center"/>

        <div class="hidden grid-cols-1 grid-cols-2 grid-cols-3 grid-cols-4"></div>
        <div class="grid gap-4" :class="'grid-cols-' + (providers.length + ($slots.default ? 1 : 0))">
            <a v-for="provider in providers" :key="provider.id"
               class="@container flex gap-2 items-center justify-center transition duration-200 border border-gray-400 dark:border-dark-600 w-full py-2.5 rounded-lg text-sm shadow-sm hover:shadow-md dark:hover:border-dark-400 font-normal text-center"
               :href="route('oauth.redirect', provider.id)">
                <ProviderIcon :provider="provider" classes="h-6 w-6 mx-2"/>
                <span class="sr-only @[8rem]:not-sr-only block font-medium text-sm text-gray-700 dark:text-dark-300">{{ provider.buttonLabel || provider.name }}</span>
            </a>

            <slot />
        </div>

        <div class="relative flex items-center pb-5 pt-3">
            <div class="flex-grow border-t border-gray-200 dark:border-dark-500"></div>
            <span class="flex-shrink text-gray-400 dark:text-dark-500 px-6">
                {{ prompt }}
             </span>
            <div class="flex-grow border-t border-gray-200 dark:border-dark-500"></div>
        </div>
    </div>
</template>
