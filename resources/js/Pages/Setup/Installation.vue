<script setup>
import PrimaryButton from "@/Components/PrimaryButton.vue";
import FullscreenCard from "@/Components/FullscreenCard.vue";
import InstallationSteps from "@/Components/Project/InstallationSteps.vue";
import {router} from "@inertiajs/vue3";

defineProps({
    apiToken: String,
});

const submit = () => {
    router.get(route('dashboard'));
};
</script>

<template>
    <FullscreenCard title="Installation" description="Complete the setup.">
        <form @submit.prevent="submit">
            <InstallationSteps :apiToken="apiToken" />

            <div class="mt-8">
                <PrimaryButton class="w-full !text-sm justify-center !p-3">
                    Go to dashboard
                </PrimaryButton>
            </div>
        </form>
    </FullscreenCard>
</template>
