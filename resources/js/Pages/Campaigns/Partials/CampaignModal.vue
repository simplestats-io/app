<script setup>
import {watch} from 'vue';
import {useForm} from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    show: Boolean,
    projects: {type: Array, default: () => []},
    defaultProjectId: [Number, String],
});

const emit = defineEmits(['close']);

const form = useForm({
    project_id: props.defaultProjectId,
    name: '',
});

watch(() => props.show, (open) => {
    if (open) {
        form.reset();
        form.project_id = props.defaultProjectId;
        form.clearErrors();
    }
});

const save = () => {
    form.post(route('campaigns.store'), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <DialogModal :show="show" @close="emit('close')">
        <template #title>Track a campaign</template>

        <template #content>
            <p class="text-sm text-gray-500 dark:text-dark-400">
                Create a campaign so you can enter its ad spend right away, even before the first visitor arrives.
                You can give it any name you like and link the matching <span class="font-mono">utm_campaign</span> value(s) afterwards.
            </p>

            <form @submit.prevent="save" class="mt-4 space-y-4">
                <div v-if="projects.length > 1">
                    <InputLabel for="campaign-project" value="Project" />
                    <SelectInput id="campaign-project" v-model="form.project_id" :options="projects" track-by="uuid" class="mt-1 block w-full" />
                    <p v-if="form.project_id === 'all'" class="mt-1 text-xs text-gray-500 dark:text-dark-400">
                        Counts the linked <span class="font-mono">utm_campaign</span> values in every project of this team.
                    </p>
                    <InputError :message="form.errors.project_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="campaign-name" value="Campaign name" />
                    <TextInput id="campaign-name" v-model="form.name" type="text" class="mt-1 block w-full" placeholder="e.g. Summer Sale 2026" autofocus />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
            </form>
        </template>

        <template #footer>
            <SecondaryButton @click="emit('close')">Cancel</SecondaryButton>
            <PrimaryButton class="ms-3" :class="{ 'opacity-25': form.processing }" :disabled="form.processing" @click="save">
                Create
            </PrimaryButton>
        </template>
    </DialogModal>
</template>
