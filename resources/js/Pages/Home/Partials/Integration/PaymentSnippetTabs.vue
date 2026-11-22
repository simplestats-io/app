<script setup>
import { nextTick, ref } from "vue";
import StaticCodeBlock from "@/Components/StaticCodeBlock.vue";
import providerHtml from "@/Pages/Home/Partials/Integration/Snippets/track_payment_provider.txt?shiki";
import contractHtml from "@/Pages/Home/Partials/Integration/Snippets/track_payment.txt?shiki";

const tabs = [
    { key: 'provider', label: 'Supported provider', title: 'One line of config', html: providerHtml },
    { key: 'contract', label: 'Own payment model', title: 'Example of the TrackablePayment contract', html: contractHtml },
];

const active = ref('provider');
const tabButtons = ref([]);

const move = async (step) => {
    const index = (tabs.findIndex(tab => tab.key === active.value) + step + tabs.length) % tabs.length;
    active.value = tabs[index].key;
    await nextTick();
    tabButtons.value[index]?.focus();
};
</script>

<template>
    <div class="flex flex-col gap-3 w-full">
        <div role="tablist" class="flex gap-2 self-center xl:self-start" @keydown.left.prevent="move(-1)" @keydown.right.prevent="move(1)">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                ref="tabButtons"
                :id="`payment-tab-${tab.key}`"
                type="button"
                role="tab"
                :aria-selected="active === tab.key"
                :aria-controls="`payment-panel-${tab.key}`"
                :tabindex="active === tab.key ? 0 : -1"
                @click="active = tab.key"
                class="rounded-full px-3 py-2 text-sm font-semibold transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                :class="active === tab.key
                    ? 'bg-indigo-600 text-white'
                    : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-500/10 dark:text-indigo-300 dark:hover:bg-indigo-500/20'"
            >
                {{ $t(tab.label) }}
            </button>
        </div>
        <StaticCodeBlock
            v-for="tab in tabs"
            v-show="active === tab.key"
            :key="tab.key"
            :id="`payment-panel-${tab.key}`"
            role="tabpanel"
            tabindex="0"
            :aria-labelledby="`payment-tab-${tab.key}`"
            :title="$t(tab.title)"
            :html="tab.html"
        />
    </div>
</template>
