<script setup>
import {Head, Link, router, useForm, usePage} from '@inertiajs/vue3';
import {computed, onMounted, onUnmounted} from 'vue';
import {Passkeys} from '@laravel/passkeys';
import {usePasskeyVerify} from '@laravel/passkeys/vue';
import FingerprintIcon from 'bootstrap-icons/icons/fingerprint.svg?component';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Socialstream from '@/Components/Socialstream.vue';
import TextInput from '@/Components/TextInput.vue';
import {useLocale} from '@/Composables/useLocale.js';

const { localizedPath } = useLocale();

const props = defineProps({
    canResetPassword: Boolean,
    status: String,
    testUser: {
        type: Object,
        default: {},
    },
});

const appType = import.meta.env.VITE_APP_TYPE;

const form = useForm({
    email: props.testUser.email ?? '',
    password: props.testUser.password ?? '',
    remember: false,
});

const submit = () => {
    form.transform(data => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const page = usePage();

const socialProviders = computed(() => page.props.socialstream.show && page.props.canRegister
    ? page.props.socialstream.providers
    : []);

const {
    verify: signInWithPasskey,
    isLoading: passkeyLoading,
    error: passkeyError,
    isSupported: passkeySupported,
} = usePasskeyVerify({
    remember: () => form.remember,
    onSuccess: (response) => router.visit(response.redirect),
});

// Not via the hook's autofill option: it shares isLoading/error with the button,
// which kept the button disabled while the picker waited and surfaced
// best-effort autofill failures as errors.
onMounted(() => {
    Passkeys.autofill({remember: () => form.remember})
        .then((response) => response && router.visit(response.redirect))
        .catch(() => {});
});

onUnmounted(() => Passkeys.cancel());

const appName = import.meta.env.VITE_APP_NAME;
</script>

<template>
    <Head :title="$t('Sign in')" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <template #title>
            {{ $t('Sign in to :appName', { appName }) }}
        </template>

        <div v-if="status" class="text-center mb-4 font-medium text-sm text-green-600 dark:text-green-400">
            {{ status }}
        </div>

        <Socialstream :error="$page.props?.errors?.socialstream || passkeyError" :prompt="$page.props.socialstream.prompt"
                      :labels="$page.props.socialstream.labels" :providers="socialProviders">
            <template v-if="passkeySupported" #default>
                <button type="button"
                        class="@container flex gap-2 items-center justify-center transition duration-200 border border-gray-400 dark:border-dark-600 w-full py-2.5 rounded-lg text-sm shadow-sm hover:shadow-md dark:hover:border-dark-400 font-normal"
                        :class="{ 'opacity-25': passkeyLoading }"
                        :disabled="passkeyLoading"
                        @click="signInWithPasskey">
                    <FingerprintIcon class="size-6 mx-2 text-gray-700 dark:text-dark-300" />
                    <span class="sr-only @[8rem]:not-sr-only block font-medium text-sm text-gray-700 dark:text-dark-300">{{ $t('Passkey') }}</span>
                </button>
            </template>
        </Socialstream>

        <form @submit.prevent="submit">
            <div>
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="username webauthn"
                    :placeholder="$t('Email')"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    :placeholder="$t('Password')"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="block mt-4">
                <label class="flex items-center">
                    <Checkbox v-model:checked="form.remember" name="remember" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-dark-400">{{ $t('Remember me') }}</span>
                </label>
            </div>

            <div class="mt-4">
                <PrimaryButton class="w-full my-6" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    <div class="w-full py-1 text-base normal-case">
                        {{ $t('Sign in') }}
                    </div>
                </PrimaryButton>
            </div>

            <div class="flex items-center justify-around mt-4">
                <Link v-if="appType === 'saas' && $page.props.canRegister" :href="localizedPath('/register')" class="text-sm text-gray-600 dark:text-dark-400 hover:text-gray-900 dark:hover:text-gray-100">
                    {{ $t('Sign up') }}
                </Link>

                <Link v-if="canResetPassword" :href="localizedPath('/forgot-password')" class="text-sm text-gray-600 dark:text-dark-400 hover:text-gray-900 dark:hover:text-gray-100">
                    {{ $t('Forgot your password?') }}
                </Link>

                <Link v-if="appType === 'saas'" :href="localizedPath('/')" class="text-sm text-gray-600 dark:text-dark-400 hover:text-gray-900 dark:hover:text-gray-100">
                    {{ $t('Home') }}
                </Link>
            </div>
        </form>
    </AuthenticationCard>
</template>
