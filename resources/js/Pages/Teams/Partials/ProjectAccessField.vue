<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SegmentedToggle from '@/Components/SegmentedToggle.vue';
import SelectInput from '@/Components/SelectInput.vue';

defineProps({
    availableProjects: Array,
    error: String,
});

const access = defineModel('access', {type: String, default: 'all'});
const projects = defineModel('projects', {type: Array, default: () => []});

const accessOptions = [
    {value: 'all', label: 'All projects'},
    {value: 'selected', label: 'Selected projects'},
];
</script>

<template>
    <div>
        <InputLabel value="Project access" />

        <div class="mt-1 text-xs text-gray-600 dark:text-dark-400">
            Limit this member to selected projects, for example to give a client access to their own project only. They won't see the other projects or the other team members.
        </div>

        <SegmentedToggle v-model="access" class="mt-2" :options="accessOptions" />

        <SelectInput
            v-if="access === 'selected'"
            v-model="projects"
            class="mt-2 w-full"
            :options="availableProjects"
            track-by="uuid"
            :multiple="true"
        />

        <InputError :message="error" class="mt-2" />
    </div>
</template>
