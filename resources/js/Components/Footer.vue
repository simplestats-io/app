<script setup>

const appName = import.meta.env.VITE_APP_NAME;
const appEnv = import.meta.env.VITE_APP_ENV;
</script>

<template>
    <footer class="relative text-center px-4 text-sm md:px-4 py-4  bg-gray-50 dark:bg-dark-800 text-gray-600 dark:text-dark-400 text-xs">
        <span>&copy; {{ new Date().getFullYear() }} {{ appName }}</span>
        <span class="px-2">&nbsp;|&nbsp;</span>
        {{ $t('A product by') }}
        <a class="hover:underline" href="https://laracraft.tech" target="_blank">Laracraft</a>
        <span class="px-2">&nbsp;|&nbsp;</span>
        <a class="hover:underline" href="/terms-of-service" target="_blank">{{ $t('Terms') }}</a>
        &nbsp;·&nbsp;
        <a class="hover:underline" href="/privacy-policy" target="_blank">{{ $t('Privacy') }}</a>
        &nbsp;·&nbsp;
        <a class="hover:underline" href="/dpa" target="_blank">{{ $t('DPA') }}</a>
        &nbsp;·&nbsp;
        <a class="hover:underline" href="/compliance" target="_blank">{{ $t('Compliance') }}</a>
        &nbsp;·&nbsp;
        <a class="hover:underline" href="/imprint" target="_blank">{{ $t('Imprint') }}</a>
    </footer>
</template>
