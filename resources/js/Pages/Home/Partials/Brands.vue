<script setup>
const brands = [
    {
        'name': 'Product Hunt',
        'url': 'https://www.producthunt.com/posts/simplestats?embed=true&utm_source=badge-featured&utm_medium=badge&utm_souce=badge-simplestats',
        'img': '/images/brands/producthunt.webp',
        'alt': 'SimpleStats - The missing statistics tool for Laravel applications | Product Hunt',
        'class': 'border-[#ff6154]',
    },
    {
        'name': 'Laravel News',
        'url': 'https://laravel-news.com/partner/simplestats',
        'img': '/images/brands/laravel-news.webp',
        'alt': 'SimpleStats - The missing analytics tool for Laravel applications | Laravel News',
        'class': 'border-[#ff2c20]',
    },
    // {
    //     'name': 'Reddit',
    //     'url': 'https://www.reddit.com/r/laravel/comments/1cbryw7/simplestats_a_lightweight_statistics_tool_for/',
    //     'img': '/images/brands/reddit.png',
    //     'alt': 'SimpleStats - a lightweight statistics tool for Laravel applications | Reddit',
    //     'class': '',
    // },
];
</script>

<template>
    <section class="mt-16 flex justify-center px-4">
        <div class="w-full max-w-screen-xl">
<!--            <SectionHeader id="partners">-->
<!--                Community&nbsp;<span class="text-indigo-500">and</span>&nbsp;Partners-->
<!--            </SectionHeader>-->
            <div class="flex flex-wrap items-center justify-center">
                <div v-for="brand in brands" class="p-3">
                    <a :href="brand.url" rel="noreferrer" target="_blank">
                        <img class="rounded-lg border hover:shadow-md" :class="brand.class" :src="brand.img" :alt="brand.alt" width="250" height="54" loading="lazy" decoding="async" />
                    </a>
                </div>
            </div>
        </div>
    </section>
</template>
