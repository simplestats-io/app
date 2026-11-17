<script setup>
import {Link} from "@inertiajs/vue3";

defineProps({
    banners: Array,
});
</script>

<template>
    <div v-if="banners.length" class="z-20 sticky bg-indigo-400 text-center px-6 text-lg font-bold border-b border-gray-600">
        <div v-for="banner in banners" :key="banner.text" class="py-1">
            <Link :href="banner.link">
                {{ banner.text }}
            </Link>
        </div>
    </div>
</template>

<style scoped>

</style>
