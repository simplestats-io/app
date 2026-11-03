import {nextTick, ref, watch} from 'vue';

const GAP = 8;

// Opens a dropdown upwards when it would run past the bottom of the viewport
// (e.g. a select at the end of a page or form) and there is more room above.
export function useDropUp(open) {
    const container = ref(null);
    const dropUp = ref(false);

    watch(open, async (isOpen) => {
        if (!isOpen) {
            dropUp.value = false;
            return;
        }

        await nextTick();

        const options = container.value?.querySelector('[role="listbox"]');
        if (!options) {
            return;
        }

        const rect = container.value.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;

        dropUp.value = spaceBelow < options.offsetHeight + GAP && rect.top > spaceBelow;
    });

    return {container, dropUp};
}
