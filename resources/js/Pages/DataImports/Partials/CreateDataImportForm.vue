<script setup>
import PrimaryButton from "@/Components/PrimaryButton.vue";
import InputLabel from "@/Components/InputLabel.vue";
import ActionMessage from "@/Components/ActionMessage.vue";
import FormSection from "@/Components/FormSection.vue";
import InputError from "@/Components/InputError.vue";
import {useForm} from "@inertiajs/vue3";
import SelectInput from "@/Components/SelectInput.vue";
import {computed, ref} from "vue";
import FileInput from "@/Components/FileInput.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import ConfirmationModal from "@/Components/ConfirmationModal.vue";
import InfoBox from "@/Components/InfoBox.vue";

const props = defineProps({
    filters: Object,
    projects: Array,
    dataImports: Object,
    importTypes: Array,
    mimeTypes: Array,
    permissions: Object,
});

const createDataImportForm = useForm({
    project_id: props.filters.project_id ?? 0,
    type: props.filters.type ?? 0,
    file: props.filters.file ?? null,
});

const createDataImport = () => {
    createDataImportForm.post(route('data-import.store'), {
        preserveScroll: true,
        onError: () => {
            dataImportBeeingCreated.value = false;
        },
        onSuccess: () => {
            createDataImportForm.reset();
            dataImportBeeingCreated.value = false;
        },
    });
};

const dataImportBeeingCreated = ref(false);
const confirmDataImportCreation = () => {
    dataImportBeeingCreated.value = true;
};

const currentImportNotFinishedYet = computed(() => {
    return props.dataImports.total > 0 && !props.dataImports.data[0].tracked_job?.finished_at;
});

</script>

<template>
    <FormSection @submitted="createDataImport">
        <template #title>
            Create new Data Import
        </template>

        <template #description>
            Although it is not required, we highly recommend importing user data before the corresponding payment data.<br>
            <br>
            If your user and payment data must meet certain conditions to be considered valid,
            such as users having verified email addresses or payments having a specific status like 'success',
            please ensure that only this data is included in the import file. Additionally,
            please provide the currency value of your payments in cents.<br>
            <br>
            Learn more here: <a class="simpleLink" href="/docs/how-to-import-existing-data.html" target="_blank">Data Import</a>
        </template>

        <template #form>
            <div v-if="! permissions.canCreateDataImport" class="col-span-6">
                <InfoBox>
                    You are not allowed to create data imports due to plan or role limitations!
                </InfoBox>
            </div>
            <div v-else-if="currentImportNotFinishedYet" class="col-span-6">
                <InfoBox>
                    You can only create <strong>one</strong> import at a time.
                    Please wait until this import has finished before starting another data import.
                    We'll send you an <strong>email</strong> as soon as the current import is complete!
                    Depending on the amount of data, this may take from minutes to hours.
                </InfoBox>
            </div>
            <div v-else class="col-span-6 sm:col-span-4">
                <InputLabel for="project_id" value="Project" />
                <SelectInput
                    id="project_id"
                    v-model="createDataImportForm.project_id"
                    track-by="uuid"
                    class="mt-1 block w-full"
                    :options="projects"
                />
                <InputError :message="createDataImportForm.errors.project_id" class="mt-2" />
            </div>

            <div v-if="createDataImportForm.project_id" class="col-span-6 sm:col-span-4">
                <InputLabel for="type" value="Import Type" />
                <SelectInput
                    id="type"
                    v-model="createDataImportForm.type"
                    class="mt-1 block w-full"
                    :options="importTypes"
                />
                <InputError :message="createDataImportForm.errors.type" class="mt-2" />
            </div>

            <div v-if="createDataImportForm.type === 'page_views'" class="col-span-6 sm:col-span-4">
                <InfoBox>
                    Page views are attached to visitors that already exist, matched by <strong>visitor_hash</strong>.
                    Import your visitors first. Rows of unknown visitors are skipped.
                    Columns: <code>visitor_hash</code>, <code>page</code>, <code>views</code> (optional, default 1), <code>time</code> (optional).
                </InfoBox>
            </div>

            <div v-if="createDataImportForm.type" class="col-span-6 sm:col-span-4">
                <InputLabel for="file" :value="`Import File (${mimeTypes.join(', ')})`" />

                <FileInput
                    id="file"
                    @input="createDataImportForm.file = $event.target.files[0]"
                    class="mt-1 block w-full"
                />
                <InputError :message="createDataImportForm.errors.file" class="mt-2" />
            </div>
        </template>

        <template #actions>
            <div v-if="createDataImportForm.file">
                <ActionMessage :on="createDataImportForm.recentlySuccessful" class="me-3">
                    Started.
                </ActionMessage>

                <PrimaryButton v-tooltip="!permissions.canCreateDataImport ? 'You are not allowed to create new data imports due to plan or role limitations!' : ''"
                               :class="{ 'opacity-25': createDataImportForm.processing }"
                               :disabled="createDataImportForm.processing || !permissions.canCreateDataImport"
                               @click="confirmDataImportCreation"
                               type="button"
                >
                    Start Import
                </PrimaryButton>
            </div>
        </template>
    </FormSection>

    <ConfirmationModal :show="dataImportBeeingCreated" @close="dataImportBeeingCreated = false">
        <template #title>
            Create Data Import
        </template>

        <template #content>
            Do you really want to import this file with the following settings?<br>
            <br>
            <strong>Project:</strong> {{ projects.filter((project) => project.uuid == createDataImportForm.project_id)[0].name }}<br>
            <strong>Import Type:</strong> {{ createDataImportForm.type }}<br>
            <strong>Import File:</strong> {{ createDataImportForm.file.name }}<br>
        </template>

        <template #footer>
            <SecondaryButton @click="dataImportBeeingCreated = false">
                Cancel
            </SecondaryButton>

            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': createDataImportForm.processing }"
                :disabled="createDataImportForm.processing"
                @click="createDataImport"
            >
                Confirm Import
            </PrimaryButton>
        </template>
    </ConfirmationModal>
</template>

<style scoped>

</style>
