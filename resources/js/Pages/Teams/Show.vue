<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteTeamForm from '@/Pages/Teams/Partials/DeleteTeamForm.vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import TeamMemberManager from '@/Pages/Teams/Partials/TeamMemberManager.vue';
import UpdateTeamNameForm from '@/Pages/Teams/Partials/UpdateTeamNameForm.vue';
import UpdateTeamAiSettingsForm from '@/Pages/Teams/Partials/UpdateTeamAiSettingsForm.vue';
import TeamEventUsage from "@/Pages/Teams/Partials/TeamEventUsage.vue";

defineProps({
    team: Object,
    availableRoles: Array,
    permissions: Object,
    aiSettings: Object,
    eventsCountMonthly: Number,
    availableProjects: Array,
    memberProjects: Object,
    invitationProjects: Object,
});
</script>

<template>
    <AppLayout title="Team Settings">
        <template #header>
            <div>
                {{ team.name }}
            </div>
        </template>

        <div>
            <UpdateTeamNameForm id="team-details" :team="team" :permissions="permissions" />

            <TeamEventUsage
                v-if="permissions.canViewEventUsage"
                id="event-usage"
                class="mt-10 sm:mt-0"
                :events-count-monthly="eventsCountMonthly"
            />

            <template v-if="$page.props.currentPlanOptions?.AiAssistant">
                <SectionBorder />

                <UpdateTeamAiSettingsForm
                    id="ai-assistant"
                    class="mt-10 sm:mt-0"
                    :team="team"
                    :ai-settings="aiSettings"
                    :permissions="permissions"
                />
            </template>

            <TeamMemberManager
                id="team-members"
                class="mt-10 sm:mt-0"
                :team="team"
                :available-roles="availableRoles"
                :user-permissions="permissions"
                :available-projects="availableProjects"
                :member-projects="memberProjects"
                :invitation-projects="invitationProjects"
            />

            <template v-if="permissions.canDeleteTeam && ! team.personal_team">
                <SectionBorder />

                <DeleteTeamForm class="mt-10 sm:mt-0" :team="team" />
            </template>
        </div>
    </AppLayout>
</template>
