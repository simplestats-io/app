<script setup>
import PasskeyConfirmButton from '@/Components/PasskeyConfirmButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteProjectForm from '@/Pages/Projects/Partials/DeleteProjectForm.vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import UpdateProjectForm from '@/Pages/Projects/Partials/UpdateProjectForm.vue';
import SearchConsoleForm from '@/Pages/Projects/Partials/SearchConsoleForm.vue';
import ActionSection from "@/Components/ActionSection.vue";
import {copyToClipboard} from "@/Helpers/ClipboardHelpers.js";
import DangerButton from "@/Components/DangerButton.vue";
import ConfirmationModal from "@/Components/ConfirmationModal.vue";
import DialogModal from "@/Components/DialogModal.vue";
import InputError from "@/Components/InputError.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import TextInput from "@/Components/TextInput.vue";
import {ref, watchEffect} from "vue";
import {useForm, usePage} from "@inertiajs/vue3";
import ProjectFavicon from "@/Components/ProjectFavicon.vue";
import EyeIcon from "bootstrap-icons/icons/eye.svg?component";
import ClipboardIcon from "bootstrap-icons/icons/clipboard.svg?component";

defineProps({
    project: Object,
    permissions: Object,
    usages: Object,
    flags: Object,
    searchConsole: Object,
    gscSites: Object,
});

const page = usePage();

const displayingToken = ref(false);

watchEffect(() => {
    displayingToken.value = page.props.jetstream.flash.token;
});

const viewApiTokenForm = useForm({});

const viewApiToken = (project) => {
    viewApiTokenForm.get(route('projects.token-view', [project]), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            viewApiTokenForm.reset();
        },
    });
};

const regenerateApiTokenForm = useForm({
    password: '',
});
const apiTokenBeingRegenerated = ref(null);

const confirmApiTokenRegeneration = (project) => {
    apiTokenBeingRegenerated.value = project;
};

const regenerateApiToken = () => {
    regenerateApiTokenForm.get(route('projects.token-regenerate', [apiTokenBeingRegenerated.value]), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            apiTokenBeingRegenerated.value = null;
            regenerateApiTokenForm.reset();
        },
    });
};
</script>

<template>
    <AppLayout title="Project">
        <template #header>
            <span class="inline-flex items-center gap-2">
                <ProjectFavicon :domain="project.domain" size="size-7" />
                {{ project.name }}
            </span>
        </template>

        <div>
            <UpdateProjectForm :project="project" :permissions="permissions" :usages="usages" :flags="flags" />

            <template v-if="searchConsole.available">
                <SectionBorder />

                <SearchConsoleForm class="mt-10 sm:mt-0" :project="project" :connection="searchConsole.connection" :reusable-accounts="searchConsole.reusable_accounts" :sites="gscSites" :can-update="permissions.canUpdateProject" />
            </template>

            <div v-if="project.tokens && project.tokens.length > 0">
                <SectionBorder />

                <div class="mt-10 sm:mt-0">
                    <ActionSection>
                        <template #title>
                            Manage API Token
                        </template>

                        <template #description>
                            Regenerate your token or view it again.
                        </template>

                        <template #content>
                            <div class="space-y-6">
                                <div v-for="token in project.tokens" :key="token.id" class="flex items-center justify-between">
                                    <div class="break-all dark:text-white">
                                        {{ token.name }}
                                    </div>

                                    <div class="flex items-center ms-2">
                                        <div v-if="token.last_used_ago" class="text-sm text-gray-400">
                                            Last used {{ token.last_used_ago }}
                                        </div>

                                        <button
                                            class="cursor-pointer ms-6 text-sm text-gray-400 underline flex items-center"
                                            @click="viewApiToken(project)"
                                        >
                                            <EyeIcon class="size-4 mr-1" />
                                            View
                                        </button>

                                        <button v-if="permissions.canRegenerateProjectToken" class="cursor-pointer ms-6 text-sm text-red-500" @click="confirmApiTokenRegeneration(project)">
                                            Regenerate
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </ActionSection>
                </div>
            </div>

            <template v-if="permissions.canDeleteProject">
                <SectionBorder />

                <DeleteProjectForm class="mt-10 sm:mt-0" :project="project" />
            </template>

            <!-- Token Value Modal -->
            <DialogModal :show="displayingToken" @close="displayingToken = false">
                <template #title>
                    API Token
                </template>

                <template #content>
                    <div>
                        Please copy your API token and paste it into your <strong>.env</strong> file!
                    </div>

                    <div class="flex justify-between mt-4 bg-gray-100 dark:bg-dark-900 px-4 py-2 rounded font-mono text-sm text-gray-500 dark:text-dark-400 break-all">
                        <div class="truncate">
                            {{ $page.props.jetstream.flash.token }}
                        </div>
                        <div>
                            <button @click="copyToClipboard($page.props.jetstream.flash.token)">
                                <ClipboardIcon class="size-5 -my-1.5 hover:scale-110" />
                            </button>
                        </div>
                    </div>
                </template>

                <template #footer>
                    <SecondaryButton @click="displayingToken = false">
                        Close
                    </SecondaryButton>
                </template>
            </DialogModal>

            <!-- Regenerate Token Confirmation Modal -->
            <ConfirmationModal :show="apiTokenBeingRegenerated != null" @close="apiTokenBeingRegenerated = null">
                <template #title>
                    Regenerate API Token
                </template>

                <template #content>
                    Are you sure you would like to regenerate this API token?
                    If you already used this token, make sure to replace it afterwards in the corresponding project!
                    Please enter your password to confirm this action.

                    <PasskeyConfirmButton @confirmed="regenerateApiTokenForm.password = ''; regenerateApiToken()" />

                    <div class="mt-4">
                        <TextInput
                            ref="passwordInput"
                            v-model="regenerateApiTokenForm.password"
                            type="password"
                            class="mt-1 block w-full"
                            placeholder="Password"
                            autocomplete="current-password"
                            @keyup.enter="regenerateApiToken"
                        />

                        <InputError :message="regenerateApiTokenForm.errors.password" class="mt-2" />
                    </div>
                </template>

                <template #footer>
                    <SecondaryButton @click="apiTokenBeingRegenerated = null">
                        Cancel
                    </SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': regenerateApiTokenForm.processing }"
                        :disabled="regenerateApiTokenForm.processing"
                        @click="regenerateApiToken"
                    >
                        Regenerate
                    </DangerButton>
                </template>
            </ConfirmationModal>
        </div>
    </AppLayout>
</template>
