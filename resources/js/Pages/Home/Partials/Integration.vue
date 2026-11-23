<script setup>
import { shallowRef } from "vue";
import SectionHeader from "@/Pages/Home/Partials/SectionHeader.vue";
import StaticCodeBlock from "@/Components/StaticCodeBlock.vue";
import BigStepBullet from "@/Pages/Home/Partials/Integration/BigStepBullet.vue";
import BigStepText from "@/Pages/Home/Partials/Integration/BigStepText.vue";
import SmallStepBullet from "@/Pages/Home/Partials/Integration/SmallStepBullet.vue";
import SmallStepText from "@/Pages/Home/Partials/Integration/SmallStepText.vue";
import StepText from "@/Pages/Home/Partials/Integration/StepText.vue";
import GridPlaceholder from "@/Pages/Home/Partials/Integration/GridPlaceholder.vue";
import installHtml from "@/Pages/Home/Partials/Integration/Snippets/install_package.txt?shiki";
import trackUserHtml from "@/Pages/Home/Partials/Integration/Snippets/track_user.txt?shiki";
import PaymentSnippet from "@/Pages/Home/Partials/Integration/PaymentSnippet.vue";
import { paymentProviders } from "@/Pages/Home/Partials/Integration/paymentProviders.js";
import PaymentProviderCloud from "@/Pages/Home/Partials/Integration/PaymentProviderCloud.vue";

const appName = import.meta.env.VITE_APP_NAME;
// shallowRef: the cloud compares the options by identity, a deep ref would wrap them in proxies.
const paymentProvider = shallowRef(paymentProviders[0]);
</script>

<template>
    <section class="flex justify-center px-4">
        <div class="w-full max-w-screen-xl">
            <SectionHeader id="integration" :eyebrow="$t('Get started')">
                {{ $t('Powerful analytics') }}&nbsp;<span class="text-indigo-500 underline">{{ $t('in no time') }}</span>
            </SectionHeader>

            <div class="flex mx-auto max-w-screen-md xl:max-w-screen-xl">
                <div class="hidden xl:block border-r-2 border-dotted border-indigo-400 relative left-1/2"></div>
                <div class="flex-grow grid gap-4 grid-cols-1 xl:grid-cols-[1fr_auto_1fr] items-center relative">
                    <GridPlaceholder />
                    <BigStepBullet content="1" />
                    <BigStepText :content="$t('Install')" />

                    <GridPlaceholder />
                    <SmallStepBullet content="" />
                    <SmallStepText :content="$t('Install the client')" />

                    <StaticCodeBlock :title="$t('Install :appName', { appName })" :html="installHtml" />
                    <GridPlaceholder />
                    <StepText :content="$t('In your Laravel project, you just need to <b>install</b> the :appName <b>client</b>. Congrats, the tool is now already tracking your visitors!', { appName })" />

                    <GridPlaceholder />
                    <BigStepBullet content="2" />
                    <BigStepText :content="$t('Track')" />

                    <GridPlaceholder />
                    <SmallStepBullet content="" />
                    <SmallStepText :content="$t('Track your users')" />

                    <StaticCodeBlock :title="$t('Example of the TrackablePerson contract')" :html="trackUserHtml" />
                    <GridPlaceholder />
                    <StepText :content="$t('To make the client <b>track</b> your user <b>registrations</b>, you only have to add the <b>TrackablePerson</b> interface and implement the contract.')" />

                    <GridPlaceholder />
                    <SmallStepBullet content="" />
                    <SmallStepText :content="$t('Track your sales')" />

                    <PaymentSnippet :provider="paymentProvider" />
                    <GridPlaceholder />
                    <div class="self-start">
                        <StepText :content="$t('Using Laravel Cashier, Lemon Squeezy or Polar? Set it as your <b>payment provider</b> with one line of config. Any other setup? Just use your own <b>custom payment model</b>.')" />
                        <PaymentProviderCloud v-model="paymentProvider" />
                    </div>

                    <GridPlaceholder />
                    <BigStepBullet content="3" />
                    <BigStepText :content="$t('Analyze')" />

                    <GridPlaceholder />
                    <GridPlaceholder />
                    <StepText class="hidden xl:block" :content="$t('You\'re all set! <b>:appName</b> is now tracking your data. Head over to your <b>dashboard</b> and discover what\'s happening in your app - in real time.', { appName })" />
                </div>
            </div>
            <div class="mt-10 text-center">{{ $t('Make sure to check out the') }} <a class="simpleLink" href="/docs" target="_blank">{{ $t('documentation') }}</a> {{ $t('for more details.') }}</div>
        </div>
    </section>
</template>
