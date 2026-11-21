<script setup>
import SectionHeader from "@/Pages/Home/Partials/SectionHeader.vue";
import {onMounted, ref} from "vue";
import {useTrans} from '@/Composables/useTrans.js';
import DashLgIcon from "bootstrap-icons/icons/dash-lg.svg?component";
import PlusLgIcon from "bootstrap-icons/icons/plus-lg.svg?component";

const trans = useTrans();

const questions = ref([
    {
        'id': 'tracking-setup',
        'title': () => trans('Why is tracking not working even though everything is set up correctly?'),
        'answer': () => trans('Most often, this is a queue-related issue. Ensure you have cleared your config cache and restarted your queue after adding the API token to your .env file.'),
        'active': false,
    },
    {
        'id': 'what-is-an-event',
        'title': () => trans('What is an event?'),
        'answer': () => trans('An event is any tracked activity - such as a unique visitor, registration, login, or payment. Each of these counts as one event towards your monthly usage.'),
        'active': false,
    },
    {
        'id': 'how-are-visitors-tracked',
        'title': () => trans('How are unique visitors tracked?'),
        'answer': () => trans('We track unique visitors in a GDPR-compliant way by generating a unique visitor hash each time a new visitor accesses your site. This hash is derived from a combination of the visitor\'s User-Agent, IP address, and a daily salt. This method enables unique visitor tracking without storing personally identifiable information such as cookies, ensuring privacy and data protection.'),
        'active': false,
    },
    {
        'id': 'compliance',
        'title': () => trans('Is SimpleStats GDPR, CCPA and PECR compliant?'),
        'answer': () => trans('Yes! SimpleStats sets no cookies, never stores IP addresses and runs entirely on your server, so you need no cookie banner for analytics. It is built and hosted in the EU, and our <a href="/dpa" target="_blank" class="simpleLink">DPA</a> applies automatically. Read more on our <a href="/compliance" target="_blank" class="simpleLink">compliance page</a>.'),
        'active': false,
    },
    {
        'id': 'custom-events',
        'title': () => trans('Do you support custom events?'),
        'answer': () => trans('Yes! You can track custom events to measure any activity that matters to your application. Check out our <a href="/docs/how-to-track-a-custom-event.html" target="_blank" class="simpleLink">documentation</a> to get started.'),
        'active': false,
    },
    {
        'id': 'open-source',
        'title': () => trans('Is SimpleStats open source?'),
        'answer': () => trans('Yes! SimpleStats is open source under the AGPL-3.0 license. The code is on <a href="https://github.com/simplestats-io/app" target="_blank" class="simpleLink">GitHub</a>, so you can verify exactly how your data is handled.'),
        'active': false,
    },
    {
        'id': 'self-hosting',
        'title': () => trans('Can I self-host SimpleStats?'),
        'answer': () => trans('Yes! SimpleStats is a standard Laravel application that you can run on your own servers for free, with no event or project limits. You need PHP 8.3+, PostgreSQL 14+ and some experience running Laravel in production. Check out the <a href="/docs/self-hosted.html" target="_blank" class="simpleLink">self-hosting docs</a> to get started. If you would rather focus on your product than on maintaining servers, our hosted version takes care of everything for you.'),
        'active': false,
    },
]);

const handleHash = () => {
    const hash = window.location.hash.slice(1);
    if (hash) {
        const index = questions.value.findIndex(q => q.id === hash);
        if (index !== -1) {
            questions.value.forEach((q, i) => q.active = i === index);
        }
    }
};

const toggleQuestion = (index) => {
    questions.value.forEach((q, i) => q.active = i === index ? !q.active : false);
    const id = questions.value[index].id;
    if (questions.value[index].active) {
        window.location.hash = id;
    } else {
        history.pushState('', document.title, window.location.pathname + window.location.search);
    }
};

onMounted(() => {
    handleHash();
    window.addEventListener('hashchange', handleHash);
});
</script>

<template>
    <section>
        <div class="container max-w-4xl px-6 py-10 mx-auto">

            <SectionHeader id="faq" :eyebrow="$t('FAQ')">
                {{ $t('Frequently asked') }}&nbsp;<span class="text-indigo-500 underline">{{ $t('questions') }}</span>
            </SectionHeader>

            <div class="space-y-8">
                <div v-for="(question, index) in questions" :id="question.id" class="border-2 border-gray-100 rounded-lg dark:border-dark-700 bg-white dark:bg-dark-900">
                    <button @click="toggleQuestion(index)" class="flex items-center justify-between w-full p-8">
                        <h3 class="font-semibold text-gray-700 dark:text-dark-300">{{ question.title() }}</h3>

                        <span v-if="question.active" class="text-gray-400 bg-gray-200 rounded-full">
                            <DashLgIcon class="size-6" />
                        </span>
                        <span v-else class="p-0.5 text-white bg-indigo-500 rounded-full">
                            <PlusLgIcon class="size-5" />
                        </span>
                    </button>

                    <div v-show="question.active">
                        <hr class="border-gray-200 dark:border-dark-700">

                        <p v-html="question.answer()" class="p-8 text-gray-500 dark:text-dark-300"></p>
                    </div>
                </div>
            </div>
            <p class="text-center mt-4">
                {{ $t('If you need more help, check out our') }} <a href="/docs/troubleshooting.html" target="_blank" class="simpleLink">{{ $t('Troubleshooting') }}</a> {{ $t('page!') }}
            </p>
        </div>
    </section>
</template>
