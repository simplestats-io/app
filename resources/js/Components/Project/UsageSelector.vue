<script setup>
import {computed, watch} from "vue";
import PersonCircleIcon from 'bootstrap-icons/icons/person-circle.svg?component';
import CoinIcon from 'bootstrap-icons/icons/coin.svg?component';
import Wallet2Icon from 'bootstrap-icons/icons/wallet2.svg?component';

const props = defineProps({
    modelValue: Number,
    usages: Object,
    stacked: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const iconSize = computed(() => props.stacked ? 'size-6' : 'size-8');

let bits = 0;

const subscriptionBit = props.usages.subscription?.bit ?? 0;
const usersBit = props.usages.users?.bit ?? 0;
const paymentsBit = props.usages.payments?.bit ?? 0;
const subscriptionPrereq = usersBit | paymentsBit;

// Visitors are the base of all attribution and the client package sends them anyway,
// so they are always on and not offered as a choice.
const visitorsBit = props.usages.visitors?.bit ?? 0;
const selectableUsages = computed(() => Object.fromEntries(
    Object.entries(props.usages).filter(([type]) => type !== 'visitors'),
));

// Follows the prop so a form reset (e.g. after creating a project) re-applies the bit.
watch(() => props.modelValue, (value) => {
    bits = (value ?? 0) | visitorsBit;

    if (bits !== value) {
        emit('update:modelValue', bits);
    }
}, { immediate: true });

const selectUsageType = (bit) => {
    bits ^= bit;

    // Subscriptions require registrations + payments: enabling it pulls those in,
    // removing either one clears it again.
    if (bit === subscriptionBit && (bits & subscriptionBit)) {
        bits |= subscriptionPrereq;
    } else if ((bit === usersBit || bit === paymentsBit) && ((bits & subscriptionPrereq) !== subscriptionPrereq)) {
        bits &= ~subscriptionBit;
    }

    emit('update:modelValue', bits);
}

</script>

<template>
    <div class="grid gap-6 text-left" :class="(stacked) ? 'grid-cols-1' : 'grid-cols-1 sm:grid-cols-3'">
        <div v-for="(track, type) in selectableUsages"
             @click="selectUsageType(track.bit)"
             class="cursor-pointer select-none rounded-xl border-2 hover:border-indigo-200 dark:hover:border-gray-400 transition"
             :class="[(track.bit & bits) ? '!border-indigo-400' : '', (stacked) ? 'p-2' : 'p-3']"
        >
            <div class="flex items-center" :class="(stacked) ? 'gap-x-2' : 'gap-x-4'">
                <div>
                    <div v-if="type === 'users'">
                        <PersonCircleIcon :class="iconSize" class="text-indigo-400" />
                    </div>
                    <div v-if="type === 'payments'">
                        <CoinIcon :class="iconSize" class="text-indigo-400" />
                    </div>
                    <div v-if="type === 'subscription'">
                        <Wallet2Icon :class="iconSize" class="text-indigo-400" />
                    </div>
                </div>
                <div class="flex justify-between w-full">
                    <div :class="(stacked) ? 'text-base' : 'text-lg font-medium'">
                        {{ track.name }}
                    </div>
                    <div>
                        <input
                            type="radio"
                            :checked="track.bit & bits"
                            class="text-indigo-500 bg-gray-100 border-gray-300 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:checked:bg-indigo-500 focus:ring-2 dark:focus:ring-indigo-500 dark:ring-offset-gray-800"
                        />
                    </div>
                </div>
            </div>
            <div v-if=" !stacked" class="flex items-center mt-3 text-sm">
                <div>
                    {{ track.descr }}
                </div>
            </div>
        </div>
    </div>
</template>
