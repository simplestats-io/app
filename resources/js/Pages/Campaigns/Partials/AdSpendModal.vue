<script setup>
import {watch} from 'vue';
import {useForm} from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    show: Boolean,
    editSpend: {type: Object, default: null},
    campaign: Object,
    teamCurrency: String,
    sourceOptions: {type: Array, default: () => []},
});

const emit = defineEmits(['close']);

const form = useForm({
    campaign_id: props.campaign.uuid,
    name: '',
    utm_source: '',
    amount: '',
    currency: props.teamCurrency,
    starts_on: '',
    ends_on: '',
});

const initForm = () => {
    if (props.editSpend) {
        form.name = props.editSpend.name ?? '';
        form.utm_source = props.editSpend.utm_source ?? '';
        form.amount = (props.editSpend.amount / 100).toString();
        form.currency = props.editSpend.currency;
        form.starts_on = props.editSpend.starts_on;
        form.ends_on = props.editSpend.ends_on;
    } else {
        form.name = '';
        form.utm_source = '';
        form.amount = '';
        form.currency = props.teamCurrency;
        form.starts_on = '';
        form.ends_on = '';
    }

    form.campaign_id = props.campaign.uuid;
    form.clearErrors();
};

watch(() => props.show, (open) => {
    if (open) {
        initForm();
    }
});

const save = () => {
    const onSuccess = () => emit('close');

    if (props.editSpend) {
        form.put(route('ad-spends.update', props.editSpend), {preserveScroll: true, onSuccess});
    } else {
        form.post(route('ad-spends.store'), {preserveScroll: true, onSuccess});
    }
};
</script>

<template>
    <DialogModal :show="show" @close="emit('close')" max-width="2xl">
        <template #title>{{ editSpend ? 'Edit ad spend' : 'Add ad spend' }} · {{ campaign.name }}</template>

        <template #content>
            <form @submit.prevent="save" class="grid grid-cols-1 sm:grid-cols-6 gap-3">
                <div class="sm:col-span-3">
                    <InputLabel for="name" value="Name (optional)" />
                    <TextInput id="name" v-model="form.name" type="text" maxlength="60" class="mt-1 block w-full" placeholder="e.g. Reddit" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel for="utm_source" value="utm_source (optional)" />
                    <TextInput id="utm_source" v-model="form.utm_source" type="text" list="ad-spend-sources" class="mt-1 block w-full font-mono" placeholder="e.g. reddit" />
                    <datalist id="ad-spend-sources">
                        <option v-for="source in sourceOptions" :key="source" :value="source" />
                    </datalist>
                    <InputError :message="form.errors.utm_source" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel for="amount" :value="`Amount (${form.currency})`" />
                    <TextInput id="amount" v-model="form.amount" type="number" step="0.01" min="0" class="mt-1 block w-full" placeholder="e.g. 500" />
                    <InputError :message="form.errors.amount" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel for="starts_on" value="From" />
                    <TextInput id="starts_on" v-model="form.starts_on" type="date" class="mt-1 block w-full" />
                    <InputError :message="form.errors.starts_on" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <InputLabel for="ends_on" value="To" />
                    <TextInput id="ends_on" v-model="form.ends_on" type="date" class="mt-1 block w-full" />
                    <InputError :message="form.errors.ends_on" class="mt-1" />
                </div>
                <p class="sm:col-span-6 text-sm text-gray-500 dark:text-dark-400">
                    Set the <span class="font-mono">utm_source</span> your ads use to see spend and cost per visitor per source.
                </p>
            </form>
        </template>

        <template #footer>
            <SecondaryButton @click="emit('close')">Cancel</SecondaryButton>
            <PrimaryButton class="ms-3" :class="{ 'opacity-25': form.processing }" :disabled="form.processing" @click="save">
                {{ editSpend ? 'Update' : 'Add' }}
            </PrimaryButton>
        </template>
    </DialogModal>
</template>
