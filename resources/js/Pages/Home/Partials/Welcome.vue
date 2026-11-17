<script setup>
import Gradient from "@/Pages/Home/Partials/Gradient.vue";
import Banners from "@/Pages/Home/Partials/Banners.vue";
import Header from "@/Pages/Home/Partials/Header.vue";
import Hero from "@/Pages/Home/Partials/Hero.vue";
import Presentation from "@/Pages/Home/Partials/Presentation.vue";
import Brands from "@/Pages/Home/Partials/Brands.vue";
import Integration from "@/Pages/Home/Partials/Integration.vue";
import Features from "@/Pages/Home/Partials/Features.vue";
import Comparison from "@/Pages/Home/Partials/Comparison.vue";
import Testimonials from "@/Pages/Home/Partials/Testimonials.vue";
import Pricing from "@/Pages/Home/Partials/Pricing.vue";
import Faq from "@/Pages/Home/Faq.vue";
import BlogPosts from "@/Pages/Home/Partials/BlogPosts.vue";
import Bottom from "@/Pages/Home/Partials/Bottom.vue";

defineProps({
    plans: Array,
    banners: Array,
    discount: Number,
    latestPosts: Array,
});
</script>

<template>
    <Gradient position="fixed" />

    <Banners :banners="banners" />

    <Header />

    <main class="min-h-screen relative">
        <Hero />

        <Presentation />

        <Brands />

        <Integration />

        <Features />

        <Comparison />

        <Testimonials />

        <Pricing :plans="plans" />

        <Faq />

        <BlogPosts :posts="latestPosts" />

        <Bottom />
    </main>
</template>
