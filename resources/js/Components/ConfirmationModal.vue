<script setup>
import Modal from './Modal.vue';
import ExclamationTriangleIcon from 'bootstrap-icons/icons/exclamation-triangle.svg?component';

const emit = defineEmits(['close']);

defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const close = () => {
    emit('close');
};
</script>

<template>
    <Modal
        :show="show"
        :max-width="maxWidth"
        :closeable="closeable"
        @close="close"
    >
        <div class="bg-white dark:bg-dark-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4 rounded-t-lg">
            <div class="sm:flex sm:items-start">
                <div class="mx-auto shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                    <ExclamationTriangleIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                </div>

                <div class="mt-3 text-center sm:mt-0 sm:ms-4 sm:text-start">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-dark-100">
                        <slot name="title" />
                    </h3>

                    <div class="mt-4 text-sm text-gray-600 dark:text-dark-300">
                        <slot name="content" />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-row justify-end px-6 py-4 bg-gray-100 dark:bg-dark-800 text-end rounded-b-lg">
            <slot name="footer" />
        </div>
    </Modal>
</template>
