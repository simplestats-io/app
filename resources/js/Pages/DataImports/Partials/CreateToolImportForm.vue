<script setup>
import SectionTitle from "@/Components/SectionTitle.vue";
import InfoBox from "@/Components/InfoBox.vue";
import ToolImportCard from "@/Pages/DataImports/Partials/ToolImportCard.vue";
import {computed} from "vue";

const props = defineProps({
    projects: Array,
    dataImports: Object,
    toolImportTypes: Array,
    permissions: Object,
});

const currentImportNotFinishedYet = computed(() => {
    return props.dataImports.total > 0 && !props.dataImports.data[0].tracked_job?.finished_at;
});
</script>

<template>
    <div class="max-w-3xl mx-auto">
        <SectionTitle>
            <template #title>
                Switch from another Tool
            </template>
            <template #description>
                Upload the export of your previous analytics tool as it is, we take care of the mapping.
                Learn more here: <a class="simpleLink" href="/docs/how-to-import-existing-data.html#importing-a-fathom-analytics-export" target="_blank">Tool Imports</a>
            </template>
        </SectionTitle>

        <div v-if="! permissions.canCreateDataImport" class="mb-4">
            <InfoBox>
                You are not allowed to create data imports due to plan or role limitations!
            </InfoBox>
        </div>
        <div v-else-if="currentImportNotFinishedYet" class="mb-4">
            <InfoBox>
                Please wait until the current import has finished before starting another one.
            </InfoBox>
        </div>

        <div class="flex flex-col gap-4">
            <ToolImportCard
                v-for="tool in toolImportTypes"
                :key="tool.id"
                :tool="tool"
                :projects="projects"
                :disabled="! permissions.canCreateDataImport || currentImportNotFinishedYet"
            />
        </div>
    </div>
</template>
