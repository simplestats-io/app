<script setup>
defineProps({
    content: String,
})
</script>

<template>
    <div class="py-0 xl:py-6 font-semibold text-indigo-600 dark:text-indigo-400 text-xl">{{ content }}</div>
</template>
