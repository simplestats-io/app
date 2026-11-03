<script setup>
import {ref} from 'vue';
import {router, useForm, usePage} from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import ActionSection from '@/Components/ActionSection.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import TextInput from '@/Components/TextInput.vue';
import EyeIcon from "bootstrap-icons/icons/eye-fill.svg?component";
import KeyIcon from "bootstrap-icons/icons/key-fill.svg?component";
import PencilIcon from "bootstrap-icons/icons/pencil-fill.svg?component";
import CheckCircleIcon from "bootstrap-icons/icons/check-circle.svg?component";
import ProjectAccessField from '@/Pages/Teams/Partials/ProjectAccessField.vue';

const props = defineProps({
    team: Object,
    availableRoles: Array,
    userPermissions: Object,
    // Only filled for members who manage the team: [{uuid, name}]
    availableProjects: {type: Array, default: () => []},
    // {userUuid: [projectUuid] | null} and {invitationId: [projectUuid] | null}, null = all projects
    memberProjects: {type: Object, default: () => ({})},
    invitationProjects: {type: Object, default: () => ({})},
});

// Administrators always see every project, so only these roles can be limited.
const restrictableRoles = ['editor', 'viewer'];

const roleIcons = {
    'admin': KeyIcon,
    'editor': PencilIcon,
    'viewer': EyeIcon,
};

const page = usePage();

const addTeamMemberForm = useForm({
    email: '',
    role: null,
    project_access: 'all',
    projects: [],
});

const updateRoleForm = useForm({
    role: null,
    project_access: 'all',
    projects: [],
});

const leaveTeamForm = useForm({});
const removeTeamMemberForm = useForm({});

const currentlyManagingRole = ref(false);
const managingRoleFor = ref(null);
const confirmingLeavingTeam = ref(false);
const teamMemberBeingRemoved = ref(null);

const addTeamMember = () => {
    addTeamMemberForm.post(route('team-members.store', props.team), {
        errorBag: 'addTeamMember',
        preserveScroll: true,
        onSuccess: () => addTeamMemberForm.reset(),
    });
};

const cancelTeamInvitation = (invitation) => {
    router.delete(route('team-invitations.destroy', invitation), {
        preserveScroll: true,
    });
};

const manageRole = (teamMember) => {
    const projectUuids = props.memberProjects[teamMember.uuid] ?? null;

    managingRoleFor.value = teamMember;
    updateRoleForm.clearErrors();
    updateRoleForm.role = teamMember.membership.role;
    updateRoleForm.project_access = projectUuids === null ? 'all' : 'selected';
    updateRoleForm.projects = projectUuids ?? [];
    currentlyManagingRole.value = true;
};

const updateRole = () => {
    updateRoleForm.put(route('team-members.update', [props.team, managingRoleFor.value]), {
        preserveScroll: true,
        onSuccess: () => currentlyManagingRole.value = false,
    });
};

const confirmLeavingTeam = () => {
    confirmingLeavingTeam.value = true;
};

const leaveTeam = () => {
    leaveTeamForm.delete(route('team-members.destroy', [props.team, page.props.auth.user]));
};

const confirmTeamMemberRemoval = (teamMember) => {
    teamMemberBeingRemoved.value = teamMember;
};

const removeTeamMember = () => {
    removeTeamMemberForm.delete(route('team-members.destroy', [props.team, teamMemberBeingRemoved.value]), {
        errorBag: 'removeTeamMember',
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => teamMemberBeingRemoved.value = null,
    });
};

const displayableRole = (role) => {
    return props.availableRoles.find(r => r.key === role).name;
};

const canLimitProjects = (role) => restrictableRoles.includes(role) && props.availableProjects.length > 0;

const projectAccessError = (form) => form.errors.projects ?? Object.entries(form.errors).find(([key]) => key.startsWith('projects.'))?.[1];

// Names of the projects a limited member or invitee may access, null for all projects.
const projectNames = (projectUuids) => {
    if (! projectUuids) {
        return null;
    }

    const names = props.availableProjects
        .filter(project => projectUuids.includes(project.uuid))
        .map(project => project.name);

    return names.length > 0 ? names.join(', ') : 'No projects';
};
</script>

<template>
    <div>
        <div v-if="userPermissions.canAddTeamMembers">
            <SectionBorder />

            <!-- Add Team Member -->
            <FormSection @submitted="addTeamMember">
                <template #title>
                    Add Team Member
                </template>

                <template #description>
                    Add a new team member to your team, allowing them to collaborate with you.
                </template>

                <template #form>
                    <div class="col-span-6">
                        <div class="max-w-xl text-sm text-gray-600 dark:text-dark-400">
                            Please provide the email address of the person you would like to add to this team.
                        </div>
                    </div>

                    <!-- Member Email -->
                    <div class="col-span-6 sm:col-span-4">
                        <InputLabel for="email" value="Email" />
                        <TextInput
                            id="email"
                            v-model="addTeamMemberForm.email"
                            type="email"
                            class="mt-1 block w-full"
                        />
                        <InputError :message="addTeamMemberForm.errors.email" class="mt-2" />
                    </div>

                    <!-- Role -->
                    <div v-if="availableRoles.length > 0" class="col-span-6 lg:col-span-4">
                        <InputLabel for="roles" value="Role" />
                        <InputError :message="addTeamMemberForm.errors.role" class="mt-2" />

                        <div class="relative z-0 mt-1 border border-gray-200 dark:border-dark-700 rounded-lg cursor-pointer">
                            <button
                                v-for="(role, i) in availableRoles"
                                :key="role.key"
                                type="button"
                                class="relative px-4 py-3 inline-flex w-full rounded-lg focus:z-10 focus:outline-none focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                                :class="{'border-t border-gray-200 dark:border-dark-700 focus:border-none rounded-t-none': i > 0, 'rounded-b-none': i != Object.keys(availableRoles).length - 1}"
                                @click="addTeamMemberForm.role = role.key"
                            >
                                <div :class="{'opacity-50': addTeamMemberForm.role && addTeamMemberForm.role != role.key}">
                                    <!-- Role Name -->
                                    <div class="flex items-center">
                                        <div class="text-sm text-gray-600 dark:text-dark-400" :class="{'font-semibold': addTeamMemberForm.role == role.key}">
                                            <component class="inline-block h-4 w-4" :is="roleIcons[role.key]" />
                                            {{ role.name }}
                                        </div>

                                        <CheckCircleIcon v-if="addTeamMemberForm.role == role.key" class="ms-2 size-5 text-green-400" />
                                    </div>

                                    <!-- Role Description -->
                                    <div class="mt-2 text-xs text-gray-600 dark:text-dark-400 text-start">
                                        {{ role.description }}
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Project Access -->
                    <ProjectAccessField
                        v-if="canLimitProjects(addTeamMemberForm.role)"
                        v-model:access="addTeamMemberForm.project_access"
                        v-model:projects="addTeamMemberForm.projects"
                        class="col-span-6 lg:col-span-4"
                        :available-projects="availableProjects"
                        :error="projectAccessError(addTeamMemberForm)"
                    />
                </template>

                <template #actions>
                    <ActionMessage :on="addTeamMemberForm.recentlySuccessful" class="me-3">
                        Added.
                    </ActionMessage>

                    <PrimaryButton :class="{ 'opacity-25': addTeamMemberForm.processing }" :disabled="addTeamMemberForm.processing">
                        Add
                    </PrimaryButton>
                </template>
            </FormSection>
        </div>

        <div v-if="team.team_invitations.length > 0 && userPermissions.canAddTeamMembers">
            <SectionBorder />

            <!-- Team Member Invitations -->
            <ActionSection class="mt-10 sm:mt-0">
                <template #title>
                    Pending Team Invitations
                </template>

                <template #description>
                    These people have been invited to your team and have been sent an invitation email. They may join the team by accepting the email invitation.
                </template>

                <!-- Pending Team Member Invitation List -->
                <template #content>
                    <div class="space-y-6">
                        <div v-for="invitation in team.team_invitations" :key="invitation.id" class="flex items-center justify-between">
                            <div class="text-gray-600 dark:text-dark-400">
                                <div>{{ invitation.email }}</div>

                                <div v-if="projectNames(invitationProjects[invitation.id])" class="text-xs text-gray-400">
                                    {{ projectNames(invitationProjects[invitation.id]) }}
                                </div>
                            </div>

                            <div class="flex items-center">
                                <!-- Cancel Team Invitation -->
                                <button
                                    v-if="userPermissions.canRemoveTeamMembers"
                                    class="cursor-pointer ms-6 text-sm text-red-500 focus:outline-none"
                                    @click="cancelTeamInvitation(invitation)"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </ActionSection>
        </div>

        <div v-if="team.users.length > 0">
            <SectionBorder />

            <!-- Manage Team Members -->
            <ActionSection class="mt-10 sm:mt-0">
                <template #title>
                    Team Members
                </template>

                <template #description>
                    <template v-if="userPermissions.canViewTeamMembers">
                        All of the people that are part of this team.
                    </template>
                    <template v-else>
                        Your membership of this team.
                    </template>
                </template>

                <!-- Team Member List -->
                <template #content>
                    <div class="space-y-6">
                        <div v-for="user in team.users" :key="user.uuid" class="flex items-center justify-between">
                            <div class="flex items-center">
                                <img class="w-8 h-8 rounded-full object-cover" :src="user.profile_photo_url" :alt="user.name">
                                <div class="ms-4 dark:text-white">
                                    <div>{{ user.name }}</div>

                                    <div v-if="projectNames(memberProjects[user.uuid])" class="text-xs text-gray-400">
                                        {{ projectNames(memberProjects[user.uuid]) }}
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center">
                                <!-- Manage Team Member Role -->
                                <button
                                    v-if="userPermissions.canUpdateTeamMembers && availableRoles.length"
                                    class="ms-2 text-sm text-gray-400 underline"
                                    @click="manageRole(user)"
                                >
                                    {{ displayableRole(user.membership.role) }}
                                </button>

                                <div v-else-if="availableRoles.length" class="ms-2 text-sm text-gray-400">
                                    {{ displayableRole(user.membership.role) }}
                                </div>

                                <!-- Leave Team -->
                                <button
                                    v-if="$page.props.auth.user.uuid === user.uuid"
                                    class="cursor-pointer ms-6 text-sm text-red-500"
                                    @click="confirmLeavingTeam"
                                >
                                    Leave
                                </button>

                                <!-- Remove Team Member -->
                                <button
                                    v-else-if="userPermissions.canRemoveTeamMembers"
                                    class="cursor-pointer ms-6 text-sm text-red-500"
                                    @click="confirmTeamMemberRemoval(user)"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </ActionSection>
        </div>

        <!-- Role Management Modal -->
        <DialogModal :show="currentlyManagingRole" @close="currentlyManagingRole = false">
            <template #title>
                Manage Access
            </template>

            <template #content>
                <div v-if="managingRoleFor">
                    <div class="relative z-0 mt-1 border border-gray-200 dark:border-dark-700 rounded-lg cursor-pointer">
                        <button
                            v-for="(role, i) in availableRoles"
                            :key="role.key"
                            type="button"
                            class="relative px-4 py-3 inline-flex w-full rounded-lg focus:z-10 focus:outline-none focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-600"
                            :class="{'border-t border-gray-200 dark:border-dark-700 focus:border-none rounded-t-none': i > 0, 'rounded-b-none': i !== Object.keys(availableRoles).length - 1}"
                            @click="updateRoleForm.role = role.key"
                        >
                            <div :class="{'opacity-50': updateRoleForm.role && updateRoleForm.role !== role.key}">
                                <!-- Role Name -->
                                <div class="flex items-center">
                                    <div class="text-sm text-gray-600 dark:text-dark-400" :class="{'font-semibold': updateRoleForm.role === role.key}">
                                        {{ role.name }}
                                    </div>

                                    <CheckCircleIcon v-if="updateRoleForm.role == role.key" class="ms-2 size-5 text-green-400" />
                                </div>

                                <!-- Role Description -->
                                <div class="mt-2 text-xs text-gray-600 dark:text-dark-400">
                                    {{ role.description }}
                                </div>
                            </div>
                        </button>
                    </div>

                    <InputError :message="updateRoleForm.errors.role" class="mt-2" />

                    <!-- Project Access -->
                    <ProjectAccessField
                        v-if="canLimitProjects(updateRoleForm.role)"
                        v-model:access="updateRoleForm.project_access"
                        v-model:projects="updateRoleForm.projects"
                        class="mt-6"
                        :available-projects="availableProjects"
                        :error="projectAccessError(updateRoleForm)"
                    />
                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="currentlyManagingRole = false">
                    Cancel
                </SecondaryButton>

                <PrimaryButton
                    class="ms-3"
                    :class="{ 'opacity-25': updateRoleForm.processing }"
                    :disabled="updateRoleForm.processing"
                    @click="updateRole"
                >
                    Save
                </PrimaryButton>
            </template>
        </DialogModal>

        <!-- Leave Team Confirmation Modal -->
        <ConfirmationModal :show="confirmingLeavingTeam" @close="confirmingLeavingTeam = false">
            <template #title>
                Leave Team
            </template>

            <template #content>
                Are you sure you would like to leave this team?
            </template>

            <template #footer>
                <SecondaryButton @click="confirmingLeavingTeam = false">
                    Cancel
                </SecondaryButton>

                <DangerButton
                    class="ms-3"
                    :class="{ 'opacity-25': leaveTeamForm.processing }"
                    :disabled="leaveTeamForm.processing"
                    @click="leaveTeam"
                >
                    Leave
                </DangerButton>
            </template>
        </ConfirmationModal>

        <!-- Remove Team Member Confirmation Modal -->
        <ConfirmationModal :show="teamMemberBeingRemoved" @close="teamMemberBeingRemoved = null">
            <template #title>
                Remove Team Member
            </template>

            <template #content>
                Are you sure you would like to remove this person from the team?
            </template>

            <template #footer>
                <SecondaryButton @click="teamMemberBeingRemoved = null">
                    Cancel
                </SecondaryButton>

                <DangerButton
                    class="ms-3"
                    :class="{ 'opacity-25': removeTeamMemberForm.processing }"
                    :disabled="removeTeamMemberForm.processing"
                    @click="removeTeamMember"
                >
                    Remove
                </DangerButton>
            </template>
        </ConfirmationModal>
    </div>
</template>
