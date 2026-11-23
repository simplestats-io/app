<script setup>
import { computed } from "vue";
import StaticCodeBlock from "@/Components/StaticCodeBlock.vue";
import providerHtml from "@/Pages/Home/Partials/Integration/Snippets/track_payment_provider.txt?shiki";
import modelHtml from "@/Pages/Home/Partials/Integration/Snippets/track_payment_model.txt?shiki";

// null: the visitor picked "Any other", which shows the own payment model.
const props = defineProps({
    provider: {
        type: Object,
        default: null,
    },
});

// The snippet is highlighted at build time for CashierStripe, the class name is a plain text token in it.
const html = computed(() => props.provider
    ? providerHtml.replaceAll('CashierStripe', props.provider.className)
    : modelHtml);
</script>

<template>
    <StaticCodeBlock
        :title="provider ? $t('One line of config') : $t('Own payment model')"
        :html="html"
    />
</template>
