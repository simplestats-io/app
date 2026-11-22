<script setup>
import {computed} from "vue";
import InputLabel from "@/Components/InputLabel.vue";
import InputError from "@/Components/InputError.vue";
import FlagsSelector from "@/Components/Project/FlagsSelector.vue";

const props = defineProps({
    modelValue: Number,
    flags: Object,
    usage: Number,
    usages: Object,
    error: String,
    stacked: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

// The paying-vs-all KPI distinctions only exist when both registrations and payments
// are tracked, so the project-properties question is irrelevant otherwise.
const visible = computed(() =>
    (props.usage & props.usages.users.bit) && (props.usage & props.usages.payments.bit)
);
</script>

<template>
    <div v-if="visible" :class="stacked ? 'mb-6' : 'col-span-6'">
        <InputLabel value="How does your project work?" />
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Pick what applies to your project, if anything.
        </p>
        <div class="mt-2">
            <FlagsSelector
                :modelValue="modelValue"
                :flags="flags"
                :stacked="stacked"
                @update:modelValue="$emit('update:modelValue', $event)"
            />
        </div>
        <InputError :message="error" class="mt-2" />
    </div>
</template>
