<script setup>
import {computed} from "vue";
import GemIcon from 'bootstrap-icons/icons/gem.svg?component';

const props = defineProps({
    modelValue: Number,
    flags: Object,
    stacked: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const iconSize = computed(() => props.stacked ? 'size-6' : 'size-8');

const flagIcons = {
    paid_only: GemIcon,
};

const toggleFlag = (bit) => {
    emit('update:modelValue', (props.modelValue ?? 0) ^ bit);
}
</script>

<template>
    <div class="grid gap-6 text-left" :class="(stacked || Object.keys(flags).length < 2) ? 'grid-cols-1' : 'grid-cols-1 sm:grid-cols-2'">
        <div v-for="(flag, key) in flags"
             @click="toggleFlag(flag.bit)"
             class="cursor-pointer select-none rounded-xl border-2 hover:border-indigo-200 dark:hover:border-gray-400 transition"
             :class="[(flag.bit & modelValue) ? '!border-indigo-400' : '', (stacked) ? 'p-2' : 'p-3']"
        >
            <div class="flex items-center" :class="(stacked) ? 'gap-x-2' : 'gap-x-4'">
                <div v-if="flagIcons[key]">
                    <component :is="flagIcons[key]" :class="iconSize" class="text-indigo-400" />
                </div>
                <div class="flex justify-between w-full">
                    <div :class="(stacked) ? 'text-base' : 'text-lg font-medium'">
                        {{ flag.name }}
                    </div>
                    <div>
                        <input
                            type="radio"
                            :checked="flag.bit & modelValue"
                            class="text-indigo-500 bg-gray-100 border-gray-300 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:checked:bg-indigo-500 focus:ring-2 dark:focus:ring-indigo-500 dark:ring-offset-gray-800"
                        />
                    </div>
                </div>
            </div>
            <div v-if=" !stacked" class="flex items-center mt-3 text-sm">
                <div>
                    {{ flag.descr }}
                </div>
            </div>
        </div>
    </div>
</template>
