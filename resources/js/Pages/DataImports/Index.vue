<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionBorder from "@/Components/SectionBorder.vue";
import ActionTable from "@/Components/ActionTable.vue";
import CreateDataImportForm from "@/Pages/DataImports/Partials/CreateDataImportForm.vue";
import CreateToolImportForm from "@/Pages/DataImports/Partials/CreateToolImportForm.vue";
import DataImportListRow from "@/Pages/DataImports/Partials/ShowDataImportRow.vue";
import {onMounted, onUnmounted, watch} from "vue";
import {router} from '@inertiajs/vue3'
import WarningBox from "@/Components/WarningBox.vue";

const props = defineProps({
    filters: Object,
    projects: Array,
    dataImports: Object,
    importTypes: Array,
    toolImportTypes: Array,
    mimeTypes: Array,
    permissions: Object,
});

let intervalId = null;

const checkAndReload = () => {
    if (props.dataImports.total > 0 && !props.dataImports.data[0].tracked_job?.finished_at) {
        if (intervalId === null) { // Stelle sicher, dass der Interval nicht mehrfach gesetzt wird
            intervalId = setInterval(function () {
                router.reload({ preserveState: true, preserveScroll: true });
            }, 5000);
        }
    } else {
        if (intervalId !== null) {
            clearInterval(intervalId);
            intervalId = null; // Setze intervalId zurück, um sicherzustellen, dass der Interval richtig gecleared wird
        }
    }
};

onMounted(() => {
    checkAndReload(); // Prüfe beim Mounten
});

onUnmounted(() => {
    if (intervalId !== null) {
        clearInterval(intervalId);
    }
});

watch(() => props.dataImports.data, (newVal, oldVal) => {
    checkAndReload(); // Prüfe bei jeder Änderung der Daten
}, { deep: true }); // `deep` ist hier wichtig, um tiefe Änderungen zu überwachen
</script>

<template>
    <AppLayout title="Data Imports">
        <template #header>
            Data Imports
        </template>

        <div>
            <div>
                <CreateDataImportForm
                    :filters="filters"
                    :projects="projects"
                    :dataImports="dataImports"
                    :importTypes="importTypes"
                    :mimeTypes="mimeTypes"
                    :permissions="permissions"
                />

                <SectionBorder />

                <CreateToolImportForm
                    :projects="projects"
                    :dataImports="dataImports"
                    :toolImportTypes="toolImportTypes"
                    :permissions="permissions"
                />

                <div v-if="dataImports.total > 0">
                    <SectionBorder />

                    <div class="mt-10 sm:mt-0">
                        <ActionTable :links="dataImports.links" >
                            <template #title>
                                Your Data Imports
                            </template>

                            <template #description>
                                Manage your Data Imports.
                            </template>

                            <template #content>
                                <tr class="text-left font-bold">
                                    <th class="pb-2 pt-3 px-3">Status</th>
                                    <th class="pb-2 pt-3 px-3">Project</th>
<!--                                    <th class="pb-2 pt-3 px-3">Type</th>-->
                                    <th class="pb-2 pt-3 px-3">Creator</th>
                                    <th class="pb-2 pt-3 px-3">Rows</th>
                                    <th class="pb-2 pt-3 px-3">Action</th>
                                </tr>
                                <tr v-if="dataImports.data[0].tracked_job?.status === 'failed'">
                                    <td colspan="8" class="border-t">
                                        <WarningBox>
                                            Something went wrong with your latest Data Import, please check your mails for more details!
                                        </WarningBox>
                                    </td>
                                </tr>
                                <tr v-else-if="! dataImports.data[0].tracked_job?.finished_at">
                                    <td colspan="8" class="border-t">
                                        <div class="h-1 bg-indigo-400 transition-all" :style="`width: ${dataImports.data[0].rows_progress}%;`"></div>
                                    </td>
                                </tr>
                                <tr v-for="dataImport in dataImports.data" :key="dataImport.uuid">
                                    <DataImportListRow :projects="projects" :dataImport="dataImport" />
                                </tr>
                            </template>
                        </ActionTable>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
