<script setup>
defineProps({
    id: String,
    eyebrow: String,
});
</script>
<template>
    <div :id="id" class="pt-32 pb-20 text-center">
        <p v-if="eyebrow" class="mb-4">
            <component
                :is="id ? 'a' : 'span'"
                :href="id ? `#${id}` : undefined"
                :aria-label="id ? $t('Jump to :section', { section: eyebrow }) : undefined"
                class="inline-flex items-center gap-2 rounded-full bg-indigo-50 dark:bg-indigo-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-indigo-700 dark:text-indigo-300 ring-1 ring-indigo-200 dark:ring-indigo-500/20"
                :class="{ 'transition-colors hover:bg-indigo-100 hover:ring-indigo-300 dark:hover:bg-indigo-500/20 dark:hover:ring-indigo-500/40': id }"
            >
                <span class="size-1.5 rounded-full bg-indigo-500"></span>
                {{ eyebrow }}
            </component>
        </p>
        <h2 class="text-3xl font-extrabold tracking-tight leading-none md:text-4xl xl:text-5xl dark:text-white">
            <slot />
        </h2>
    </div>
</template>
