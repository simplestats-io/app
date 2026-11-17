<script setup>
import {Link} from "@inertiajs/vue3";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import {useLocale} from "@/Composables/useLocale.js";
import {onMounted, onUnmounted, ref} from 'vue';

const { localizedPath } = useLocale();
const appName = import.meta.env.VITE_APP_NAME;

const hasScrolled = ref(false);
let scrollFrame = null;

const handleScroll = () => {
    if (scrollFrame !== null) {
        return;
    }

    scrollFrame = requestAnimationFrame(() => {
        scrollFrame = null;
        hasScrolled.value = window.scrollY > 50;
    });
};

onMounted(() => {
    window.addEventListener('scroll', handleScroll, { passive: true });
    handleScroll();
});

onUnmounted(() => {
    window.removeEventListener('scroll', handleScroll);

    if (scrollFrame !== null) {
        cancelAnimationFrame(scrollFrame);
    }
});
</script>

<template>
    <div class="animationElement z-10 sticky top-0" :class="{ 'bg-white/90 dark:bg-dark-900/90 backdrop-blur-sm border-b border-gray-200/90 dark:border-dark-700/90 shadow-sm dark:shadow-dark-800/50': hasScrolled }">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-3">
            <a :href="localizedPath('/')" class="inline-flex items-center" :aria-label="$t(':appName homepage', { appName })">
                <ApplicationLogo />
            </a>

            <div class="flex items-center gap-6">
                <a :href="localizedPath('/') + '#features'" class="hidden lg:block hover:text-indigo-600">{{ $t('Features') }}</a>
                <a :href="localizedPath('/') + '#pricing'" class="hidden lg:block hover:text-indigo-600">{{ $t('Pricing') }}</a>

                <a href="/docs" class="px-3 hover:text-indigo-600">{{ $t('Docs') }}</a>

                <Link v-if="$page.props.auth.user" :href="route('dashboard')" class=" text-gray-600 hover:text-gray-900 dark:text-dark-400 dark:hover:text-white focus:outline focus:outline-2 focus:rounded-sm focus:outline-red-500">
                    {{ $t('Dashboard') }}
                </Link>
                <template v-else>
                    <Link v-if="$page.props.canLogin" :href="localizedPath('/login')" class="hover:text-indigo-600">{{ $t('Sign in') }}</Link>

                    <Link v-if="$page.props.canRegister" :href="localizedPath('/register')" class=" text-white rounded-lg bg-indigo-600 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-900 hidden px-5 py-1 text-center md:inline-block transition-all duration-200 hover:scale-105">
                        {{ $t('Get started') }}
                    </Link>
                </template>

            </div>
        </div>
    </div>
</template>

<style scoped>
.animationElement {
  animation: fadeIn 0.6s ease forwards;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
