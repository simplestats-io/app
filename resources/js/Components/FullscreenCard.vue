<script setup>
import ArrowLeftIcon from 'bootstrap-icons/icons/arrow-left.svg?component';
import ArrowLeftEndOnRectangleIcon from 'bootstrap-icons/icons/box-arrow-in-right.svg?component';
import {Head, router} from '@inertiajs/vue3';
import Gradient from "@/Pages/Home/Partials/Gradient.vue";
import Banner from "@/Components/Banner.vue";
import TeamDropdown from "@/Pages/Teams/Partials/TeamDropdown.vue";

defineProps({
    title: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
    wide: {
        type: Boolean,
        default: false,
    },
});

const goBack = () => window.history.back();
const logout = () => router.post(route('logout'));
</script>

<template>
    <Head :title="title" />
    <Gradient position="fixed" />
    <Banner />
    <div class="fixed top-4 left-4 z-50 flex gap-2">
        <button @click="goBack" class="p-2 rounded-full dark:bg-dark-600/50 dark:hover:bg-dark-500/50">
            <ArrowLeftIcon class="size-5"/>
        </button>
        <button @click="logout" class="p-2 rounded-full dark:bg-dark-600/50 dark:hover:bg-dark-500/50">
            <ArrowLeftEndOnRectangleIcon class="size-5"/>
        </button>
        <div v-if="$page.props.auth.user.all_teams.length > 1">
            <TeamDropdown align="left" @switched-team="() => router.visit(route('dashboard'))"/>
        </div>
    </div>
    <div class="w-full min-h-screen flex items-center">
        <div class="mx-auto w-full p-1.5 rounded-lg bg-gray-200/50 dark:bg-dark-600/50 fade-in-bottom" :class="wide ? 'sm:max-w-2xl' : 'sm:max-w-lg'">
        <div class="relative p-10 border rounded-md bg-white dark:bg-dark-800">
                <h2 v-if="title" class="text-xl font-semibold">{{ title }}</h2>
                <p v-if="description" class="mb-6">{{ description }}</p>
                <slot/>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes fadeInBottom {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fade-in-bottom {
    animation: fadeInBottom 0.6s ease-out;
}
</style>
