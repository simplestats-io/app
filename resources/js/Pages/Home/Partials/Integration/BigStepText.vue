<script setup>
defineProps({
    content: String,
})
</script>

<template>
    <div class="hidden xl:block text-indigo-600 dark:text-indigo-400 uppercase font-bold text-2xl">{{ content }}</div>
</template>
