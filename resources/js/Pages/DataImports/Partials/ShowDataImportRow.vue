<script setup>
import {useForm} from "@inertiajs/vue3";
import {ref} from "vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import ConfirmationModal from "@/Components/ConfirmationModal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import {truncate} from "@/Helpers/StringHelpers.js";
import ProjectFavicon from "@/Components/ProjectFavicon.vue";
import ArrowPathIcon from "bootstrap-icons/icons/arrow-clockwise.svg?component";
import ArrowUturnLeftIcon from "bootstrap-icons/icons/arrow-return-left.svg?component";
import CheckCircleIcon from "bootstrap-icons/icons/check-circle.svg?component";
import ClockIcon from "bootstrap-icons/icons/clock.svg?component";
import QuestionMarkCircleIcon from "bootstrap-icons/icons/question-circle.svg?component";
import XCircleIcon from "bootstrap-icons/icons/x-circle.svg?component";
import XMarkIcon from "bootstrap-icons/icons/x-lg.svg?component";

const props = defineProps({
    projects: Array,
    dataImport: Object,
});

const handleDataImportForm = useForm({});

const handleDataImport = () => {
    let action = dataImportBeeingHandled.value;

    handleDataImportForm.post(route('data-import.'+action, props.dataImport), {
        preserveScroll: true,
        onFinish: () => {
            dataImportBeeingHandled.value = false;
        },
    });
};

const dataImportBeeingHandled = ref(false);
const confirmDataImportAction = (action) => {
    dataImportBeeingHandled.value = action;
};

const getTrackedJobStatusOptions = (trackedJob) => {
    const statusOptions = {
        icon: QuestionMarkCircleIcon,
        text: 'unknown',
        color: 'text-gray-600',
        svgClass: '',
    };

    if (!trackedJob) {
        return statusOptions;
    }

    statusOptions.text = trackedJob.status;

    // Check rolled_back_at on the dataImport itself
    if (props.dataImport.rolled_back_at) {
        statusOptions.icon = ClockIcon;
        statusOptions.text = 'rolled back';
        statusOptions.color = 'text-orange-600';
        return statusOptions;
    }

    switch (trackedJob.status) {
        case 'queued':
        case 'retrying':
        case 'started':
            statusOptions.icon = ArrowPathIcon;
            statusOptions.color = 'text-indigo-600';
            statusOptions.text = 'processing';
            statusOptions.svgClass = 'animate-spin';
            break;
        case 'finished':
            statusOptions.icon = CheckCircleIcon;
            statusOptions.text = 'completed';
            statusOptions.color = 'text-green-600';
            break;
        case 'failed':
            statusOptions.icon = XCircleIcon;
            statusOptions.color = 'text-red-600';
            break;
    }

    return statusOptions;
};

</script>

<template>
    <td class="border-t w-px px-1 py-1">
        <div class="flex items-center" :class="getTrackedJobStatusOptions(dataImport.tracked_job).color">
            <div class="mr-4 text-center">
                <component
                    :class="getTrackedJobStatusOptions(dataImport.tracked_job).svgClass"
                    class="inline-block size-5 ml-2" :is="getTrackedJobStatusOptions(dataImport.tracked_job).icon"
                    v-tooltip="getTrackedJobStatusOptions(dataImport.tracked_job).text"
                />
                <span v-if="! dataImport.tracked_job?.finished_at" class="block">
                    {{ dataImport.rows_progress }}%
                </span>
            </div>
            <div>
                <div class="text-sm">
                    <span v-if="dataImport.tracked_job?.finished_at">
                        {{ new Date(dataImport.tracked_job.finished_at).toLocaleString() }}
                    </span>
                    <span v-else-if="dataImport.tracked_job?.started_at">
                        {{ new Date(dataImport.tracked_job.started_at).toLocaleString() }}
                    </span>
                    <span v-else-if="dataImport.tracked_job?.created_at">
                        {{ new Date(dataImport.tracked_job.created_at).toLocaleString() }}
                    </span>
                </div>
                <div class="font-medium first-letter:uppercase">
                    {{ dataImport.type }}
                </div>
            </div>
        </div>
    </td>
    <td class="border-t px-2 py-1 focus:text-indigo-500">
        <span class="inline-flex items-center gap-1">
            <ProjectFavicon :domain="dataImport.project.domain" />
            {{ truncate(dataImport.project.name, { length: 15 }) }}
        </span>
    </td>
<!--    <td class="border-t px-2 py-1 focus:text-indigo-500">-->
<!--        {{ dataImport.type }}-->
<!--    </td>-->
    <td class="border-t px-2 py-1 focus:text-indigo-500">
        {{ truncate(dataImport.created_by.name, { length: 15 }) }}
    </td>
    <td class="border-t px-2 py-1 focus:text-indigo-500">
        {{ dataImport.rows_imported ?? 0 }}
    </td>
    <td class="border-t px-2 py-1 focus:text-indigo-500">
        <PrimaryButton v-if="! dataImport.tracked_job?.finished_at" v-tooltip="'Cancel'" @click="confirmDataImportAction('cancel')">
            <XMarkIcon class="size-4" />
        </PrimaryButton>
        <DangerButton v-else-if="! dataImport.rolled_back_at && dataImport.rows_imported > 0 && dataImport.type !== 'page_views'" v-tooltip="'Rollback'" @click="confirmDataImportAction('rollback')">
            <ArrowUturnLeftIcon class="size-4" />
        </DangerButton>
        <span v-else>
            -
        </span>
        <div>
            <ConfirmationModal :show="dataImportBeeingHandled" @close="dataImportBeeingHandled = false">
                <template #title>
                    <span class="capitalize">
                        {{ dataImportBeeingHandled }} Data Import
                    </span>
                </template>

                <template #content>
                    <h3 class="text-red-500 text-lg font-bold">Warning - This action cannot be undone!</h3>
                    <br>
                    Are you sure you want to <strong>{{ dataImportBeeingHandled }}</strong> the following data import?<br>
                    <br>
                    <strong>Project:</strong> {{ dataImport.project?.name }}<br>
                    <strong>Import Type:</strong> {{ dataImport.type }}<br>
                    <span v-if="dataImport.rows_imported > 0">
                        <strong>Rows:</strong> {{ dataImport.rows_imported }} rows<br>
                    </span>
                </template>

                <template #footer>
                    <SecondaryButton @click="dataImportBeeingHandled = false">
                        Nevermind
                    </SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': handleDataImportForm.processing }"
                        :disabled="handleDataImportForm.processing"
                        @click="handleDataImport"
                    >
                        {{ dataImportBeeingHandled }} Import
                    </DangerButton>
                </template>
            </ConfirmationModal>
        </div>
    </td>
</template>

<style scoped>

</style>
