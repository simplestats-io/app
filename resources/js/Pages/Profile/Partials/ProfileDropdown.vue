<script setup>
import DropdownLink from "@/Components/DropdownLink.vue";
import Dropdown from "@/Components/Dropdown.vue";
import {router} from "@inertiajs/vue3";
import ChevronDownIcon from "bootstrap-icons/icons/chevron-down.svg?component";
import PersonIcon from "bootstrap-icons/icons/person.svg?component";
import BellIcon from "bootstrap-icons/icons/bell.svg?component";
import FileImportIcon from "bootstrap-icons/icons/file-arrow-up.svg?component";
import CreditCardIcon from "bootstrap-icons/icons/credit-card.svg?component";
import GearIcon from "bootstrap-icons/icons/gear.svg?component";
import StackIcon from "bootstrap-icons/icons/stack.svg?component";
import ActivityIcon from "bootstrap-icons/icons/activity.svg?component";
import SignOutIcon from "bootstrap-icons/icons/box-arrow-right.svg?component";
import LightBulbIcon from "bootstrap-icons/icons/lightbulb.svg?component";
import QuestionMarkCircleIcon from "bootstrap-icons/icons/question-circle.svg?component";

defineProps({
    triggerText: {
        type: String,
        default: '',
    }
})

defineEmits(['feedback']);

const logout = () => {
    router.post(route('logout'));
};

const supportAction = () => {
    window.open('https://tawk.to/chat/6601f04ea0c6737bd124880f/1hprp5d8g');
};

const appType = import.meta.env.VITE_APP_TYPE;
</script>

<template>
    <Dropdown align="left" width="48" class="ml-auto">
        <template #trigger>
            <div v-if="$page.props.jetstream.managesProfilePhotos" class="text-center">
                <button class="border-2 border-gray-500 rounded-full focus:outline-none transition">
                    <img class="size-8 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">
                </button>
                <div v-if="triggerText" class="text-xs w-[58px] truncate">{{ triggerText }}</div>
            </div>
            <span v-else class="inline-flex rounded-md">
                <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-dark-400 bg-white dark:bg-dark-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none focus:bg-gray-50 dark:focus:bg-gray-700 active:bg-gray-50 dark:active:bg-gray-700 transition ease-in-out duration-150">
                    {{ $page.props.auth.user.name }}

                    <ChevronDownIcon class="ms-2 -me-0.5 size-4" />
                </button>
            </span>
        </template>

        <template #content>
            <div class="px-4 pt-2 pb-1 text-xs text-gray-400">
                Account
            </div>

            <DropdownLink :href="route('profile.show')">
                <div class="flex items-center gap-2">
                    <div>
                        <PersonIcon class="size-4" />
                    </div>
                    <div>Profile</div>
                </div>
            </DropdownLink>

            <DropdownLink :href="route('notifications.index')">
                <div class="flex items-center gap-2">
                    <div>
                        <BellIcon class="size-4" />
                    </div>
                    <div>Notifications</div>
                </div>
            </DropdownLink>

            <DropdownLink as="a" :href="route('data-import.index')">
                <div class="flex items-center gap-2">
                    <div>
                        <FileImportIcon class="size-4" />
                    </div>
                    <div>Data Import</div>
                </div>
            </DropdownLink>

            <DropdownLink v-if="appType === 'saas'" as="a" :href="route('spark.portal')">
                <div class="flex items-center gap-2">
                    <div>
                        <CreditCardIcon class="size-4" />
                    </div>
                    <div>Subscription</div>
                </div>
            </DropdownLink>

            <template v-if="$page.props.isAdmin">
                <div class="border-t border-gray-200 dark:border-dark-600" />

                <div class="px-4 pt-2 pb-1 text-xs text-gray-400">
                    Administration
                </div>

                <DropdownLink v-if="appType === 'saas'" as="a" href="/admin">
                    <div class="flex items-center gap-2">
                        <div>
                            <GearIcon class="size-4" />
                        </div>
                        <div>Admin</div>
                    </div>
                </DropdownLink>

                <DropdownLink as="a" href="/horizon">
                    <div class="flex items-center gap-2">
                        <div>
                            <StackIcon class="size-4" />
                        </div>
                        <div>Horizon</div>
                    </div>
                </DropdownLink>

                <DropdownLink as="a" href="/pulse">
                    <div class="flex items-center gap-2">
                        <div>
                            <ActivityIcon class="size-4" />
                        </div>
                        <div>Pulse</div>
                    </div>
                </DropdownLink>
            </template>

            <template v-if="$page.props.feedbackTypes?.length || appType === 'saas'">
                <div class="border-t border-gray-200 dark:border-dark-600" />

                <div class="px-4 pt-2 pb-1 text-xs text-gray-400">
                    Help
                </div>
            </template>

            <DropdownLink v-if="$page.props.feedbackTypes?.length" as="button" @click="$emit('feedback')">
                <div class="flex items-center gap-2">
                    <div>
                        <LightBulbIcon class="size-4" />
                    </div>
                    <div>Feedback</div>
                </div>
            </DropdownLink>

            <DropdownLink v-if="appType === 'saas'" as="button" @click="supportAction">
                <div class="flex items-center gap-2">
                    <div>
                        <QuestionMarkCircleIcon class="size-4" />
                    </div>
                    <div>Support</div>
                </div>
            </DropdownLink>

            <div class="border-t border-gray-200 dark:border-dark-600" />

            <!-- Authentication -->
            <form @submit.prevent="logout">
                <DropdownLink as="button">
                    <div class="flex items-center gap-2">
                        <div>
                            <SignOutIcon class="size-4" />
                        </div>
                        <div>Log Out</div>
                    </div>
                </DropdownLink>
            </form>
        </template>
    </Dropdown>
</template>
