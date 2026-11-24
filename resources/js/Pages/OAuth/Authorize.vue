<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import EyeIcon from 'bootstrap-icons/icons/eye.svg?component';
import ExclamationIcon from 'bootstrap-icons/icons/exclamation-triangle.svg?component';
import PencilIcon from 'bootstrap-icons/icons/pencil-square.svg?component';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    client: Object,
    scopes: Array,
    requestsWrite: Boolean,
    authToken: String,
    authorizeUrl: String,
    csrfToken: String,
});

const allowWrite = ref(false);

const scopeIcons = { 'mcp:use': EyeIcon, 'mcp:write': PencilIcon };
</script>

<template>
    <Head title="Authorize AI client" />

    <AuthenticationCard>
        <template #title>
            Authorize {{ client.name }}
        </template>

        <div class="mb-6 text-sm text-gray-600 dark:text-dark-400">
            <p>
                <span class="font-medium text-gray-900 dark:text-dark-100">{{ client.name }}</span>
                wants to access SimpleStats as <span class="font-medium text-gray-900 dark:text-dark-100">{{ $page.props.auth.user?.name }}</span>,
                with your role and project access. It always works on the team selected in the app,
                currently <span class="font-medium text-gray-900 dark:text-dark-100">{{ $page.props.auth.user?.current_team?.name }}</span>.
            </p>
            <p v-if="client.redirect_host" class="mt-2">
                After approving you will be sent back to <span class="font-mono">{{ client.redirect_host }}</span>.
            </p>
        </div>

        <div class="mb-6 flex gap-3 items-start rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
            <ExclamationIcon class="size-5 shrink-0" />
            <p>Unverified client: the name was chosen by the client itself. Only approve if you just connected it yourself.</p>
        </div>

        <ul class="mb-6 space-y-3">
            <li v-for="scope in scopes" :key="scope.id"
                class="flex gap-3 items-start text-sm text-gray-700 dark:text-dark-300"
                :class="{ 'line-through opacity-50': !allowWrite && scope.id === 'mcp:write' }">
                <component :is="scopeIcons[scope.id] ?? EyeIcon" class="size-5 shrink-0 text-indigo-500" />
                <span>{{ scope.description }}</span>
            </li>
        </ul>

        <form method="POST" :action="authorizeUrl">
            <input type="hidden" name="_token" :value="csrfToken">
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" :value="client.id">
            <input type="hidden" name="auth_token" :value="authToken">
            <input v-if="allowWrite" type="hidden" name="allow_write" value="1">

            <label v-if="requestsWrite" class="flex items-center">
                <Checkbox v-model:checked="allowWrite" />
                <span class="ms-2 text-sm text-gray-600 dark:text-dark-400">Also allow changes (campaigns, projects, team settings, invitations)</span>
            </label>

            <PrimaryButton type="submit" class="w-full mt-6">
                <div class="w-full py-1 text-base normal-case">
                    Authorize
                </div>
            </PrimaryButton>
        </form>

        <form method="POST" :action="authorizeUrl">
            <input type="hidden" name="_token" :value="csrfToken">
            <input type="hidden" name="_method" value="DELETE">
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" :value="client.id">
            <input type="hidden" name="auth_token" :value="authToken">

            <button type="submit"
                    class="w-full mt-3 py-2.5 rounded-lg text-sm font-medium border border-gray-400 dark:border-dark-600 text-gray-700 dark:text-dark-300 shadow-sm hover:shadow-md dark:hover:border-dark-400 transition duration-200">
                Cancel
            </button>
        </form>
    </AuthenticationCard>
</template>
