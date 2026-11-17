<script setup>
import SectionHeader from "@/Pages/Home/Partials/SectionHeader.vue";
import {testimonials} from "@/Data/testimonials.js";
</script>

<template>
    <section class="flex justify-center">
        <div class="w-full max-w-screen-xl px-6">
            <SectionHeader id="testimonials" :eyebrow="$t('Loved by devs')">
                {{ $t('Testimonials of our') }} <span class="text-indigo-500 underline">{{ $t('fans') }}</span>
            </SectionHeader>

            <div class="gap-x-12 columns-1 md:columns-2 xl:columns-3">
                <figure
                    v-for="t in testimonials"
                    :key="t.name"
                    class="break-inside-avoid pt-8 pb-10 border-t border-gray-950/10 dark:border-white/10"
                >
                    <div class="font-serif leading-none text-5xl text-indigo-500/70 dark:text-indigo-400/70 select-none">&ldquo;</div>
                    <blockquote class="mt-3 text-lg leading-relaxed italic text-gray-800 dark:text-dark-200">
                        <p>{{ t.descr }}</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-3">
                        <img
                            class="size-10 rounded-full object-cover shrink-0"
                            :src="t.avatar"
                            :alt="t.name"
                            width="40"
                            height="40"
                            loading="lazy"
                        />
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                <a v-if="t.social_link" :href="t.social_link" target="_blank" rel="noopener" class="hover:text-indigo-600 dark:hover:text-indigo-400">{{ t.name }}</a>
                                <span v-else>{{ t.name }}</span>
                            </div>
                            <div class="mt-1.5 text-xs text-gray-500 dark:text-dark-400 truncate">
                                {{ t.title }}{{ t.company ? ', ' : '' }}<a v-if="t.company_link" class="hover:text-indigo-600 dark:hover:text-indigo-400" :href="t.company_link" target="_blank" rel="noopener">{{ t.company }}</a><span v-else>{{ t.company }}</span>
                            </div>
                        </div>
                    </figcaption>
                </figure>
            </div>
        </div>
    </section>
</template>
