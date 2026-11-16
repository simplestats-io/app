<script setup>
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import InputLabel from "@/Components/InputLabel.vue";
import InputError from "@/Components/InputError.vue";
import SelectInput from "@/Components/SelectInput.vue";
import FileInput from "@/Components/FileInput.vue";
import ConfirmationModal from "@/Components/ConfirmationModal.vue";
import ProjectFavicon from "@/Components/ProjectFavicon.vue";
import InfoTooltip from "@/Components/InfoTooltip.vue";
import {useForm} from "@inertiajs/vue3";
import {computed, ref} from "vue";

const props = defineProps({
    tool: Object,
    projects: Array,
    disabled: Boolean,
});

const form = useForm({
    project_id: 0,
    type: props.tool.id,
    file: null,
});

const fileInputKey = ref(0);
const confirmingImport = ref(false);

const selectedProjectName = computed(() => props.projects.find((project) => project.uuid === form.project_id)?.name);

const startImport = () => {
    form.post(route('data-import.store'), {
        preserveScroll: true,
        onFinish: () => {
            confirmingImport.value = false;
        },
        onSuccess: () => {
            form.reset();
            fileInputKey.value++;
        },
    });
};
</script>

<template>
<div>
    <div class="flex flex-col gap-4 p-5 bg-white dark:bg-dark-800 border rounded-md shadow">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <ProjectFavicon :domain="tool.domain" size="size-8" />
                <h4 class="text-base font-semibold text-gray-900 dark:text-dark-100">{{ tool.name }}</h4>
            </div>

            <InfoTooltip
                v-if="tool.instructions"
                :title="`${tool.name} Export`"
                :text="tool.instructions"
                placement="left"
                icon-class="size-5"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <div>
                <InputLabel :for="`${tool.id}_project_id`" value="Project" />
                <SelectInput
                    :id="`${tool.id}_project_id`"
                    v-model="form.project_id"
                    track-by="uuid"
                    class="mt-1 block w-full"
                    :options="projects"
                />
            </div>

            <div>
                <InputLabel :for="`${tool.id}_file`" value="Export File (csv)" />
                <FileInput
                    :id="`${tool.id}_file`"
                    :key="fileInputKey"
                    accept=".csv,.xls,.xlsx"
                    class="mt-1 block w-full"
                    @input="form.file = $event.target.files[0] ?? null"
                />
            </div>

            <PrimaryButton
                type="button"
                class="justify-center py-2.5"
                :disabled="disabled || ! form.project_id || ! form.file || form.processing"
                :class="{ 'opacity-25': disabled || ! form.project_id || ! form.file || form.processing }"
                @click="confirmingImport = true"
            >
                Start Import
            </PrimaryButton>
        </div>

        <div v-if="form.hasErrors">
            <InputError :message="form.errors.project_id" />
            <InputError :message="form.errors.file ?? form.errors.type" />
        </div>
    </div>

    <ConfirmationModal :show="confirmingImport" @close="confirmingImport = false">
        <template #title>
            Import {{ tool.name }} Export
        </template>

        <template #content>
            Do you really want to import this export?<br>
            <br>
            <strong>Project:</strong> {{ selectedProjectName }}<br>
            <strong>Import File:</strong> {{ form.file?.name }}<br>
        </template>

        <template #footer>
            <SecondaryButton @click="confirmingImport = false">
                Cancel
            </SecondaryButton>

            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
                @click="startImport"
            >
                Confirm Import
            </PrimaryButton>
        </template>
    </ConfirmationModal>
</div>
</template>
