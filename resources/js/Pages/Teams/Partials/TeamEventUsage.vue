<script setup>
import ActionSection from '@/Components/ActionSection.vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import {usePage} from "@inertiajs/vue3";
import {format} from 'date-fns';
import ProjectFavicon from "@/Components/ProjectFavicon.vue";

const props = defineProps({
    eventsCountMonthly: Number,
});

const page = usePage();
const used = props.eventsCountMonthly || 0;
const total = page.props.currentPlanOptions?.Events || 0;
const isUnlimited = total === -1;
const projects = page.props.projectsEventUsage || [];

const dateRange = `${format(new Date(), 'MMMM yyyy')}`;

const getUsagePercentage = () => {
    if (total === 0) return 0;
    return Math.floor((used / total) * 100);
};

const getProjectPercentage = (projectCount) => {
    if (used === 0) return 0;
    return Math.floor((projectCount / used) * 100);
};
</script>

<template>
    <div>
        <div>
            <SectionBorder />

            <ActionSection>
                <template #title>
                    Team Events Usage
                </template>

                <template #description>
                    Here you can see your team's event usage for the <strong>current month</strong>.<br>
                    This helps you keep track and plan ahead.
                    <template v-if="!isUnlimited">We'll notify you when you're getting close to your plan limit.</template>
                </template>

                <template #content>
                    <div class="text-gray-600 dark:text-dark-400">
                        <div class="w-full text-sm font-medium flex justify-between">
                            <div>Total usage - {{ dateRange }}</div>
                            <div v-if="isUnlimited">
                                {{ used.toLocaleString() }} events
                                <span class="text-gray-500 dark:text-dark-500">(unlimited)</span>
                            </div>
                            <div v-else>
                                {{ used.toLocaleString() }} / {{ total.toLocaleString() }} events
                                <span class="text-gray-500 dark:text-dark-500">({{ getUsagePercentage() }}%)</span>
                            </div>
                        </div>
                        <div v-if="!isUnlimited" class="mt-3" aria-hidden="true">
                            <div class="overflow-hidden rounded-full bg-gray-200 dark:bg-dark-300">
                                <div class="h-2 rounded-full bg-indigo-500" :style="`width: ${getUsagePercentage()}%`" />
                            </div>
                        </div>

                        <div v-if="projects.length > 1" class="mt-10">
                            <div class="font-semibold tracking-wide text-gray-500 dark:text-dark-500 mb-3">
                                Usage per project:
                            </div>

                            <div class="space-y-3">
                                <div v-for="project in projects" :key="project.uuid">
                                    <div class="flex justify-between text-sm">
                                        <div class="flex items-center gap-1.5">
                                            <ProjectFavicon :domain="project.domain" />
                                            {{ project.name }}
                                        </div>
                                        <div>
                                            {{ project.events_count_monthly.toLocaleString() }} events
                                            <span class="text-gray-500 dark:text-dark-500">({{ getProjectPercentage(project.events_count_monthly) }}%)</span>
                                        </div>
                                    </div>
                                    <div class="mt-1.5" aria-hidden="true">
                                        <div class="overflow-hidden rounded-full bg-gray-200 dark:bg-dark-300">
                                            <div
                                                class="h-1.5 rounded-full bg-indigo-500"
                                                :style="`width: ${getProjectPercentage(project.events_count_monthly)}%`"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </ActionSection>
        </div>
    </div>
</template>
