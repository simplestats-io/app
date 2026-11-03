<script setup>
import {computed, nextTick, ref, watch} from 'vue'
import {Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions} from '@headlessui/vue'
import ChevronUpDownIcon from 'bootstrap-icons/icons/chevron-expand.svg?component'
import CheckIcon from 'bootstrap-icons/icons/check2.svg?component'
import {useDropUp} from '@/Composables/useDropUp.js'
import SearchIcon from 'bootstrap-icons/icons/search.svg?component'

const props = defineProps({
    modelValue: [Array, Object, String, Number, null],
    options: { type: [Array, Object], required: true },
    multiple: { type: Boolean, default: false },
    trackBy: { type: String, default: 'id' },
    labelBy: { type: String, default: 'name' },
    searchBy: { type: Array, default: null },
    class: { type: String, default: '' },
    optionsClass: { type: String, default: '' },
    name: { type: String, default: '' },
    id: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'change'])

const open = ref(false)
const {container: dropdownContainer, dropUp} = useDropUp(open)
const query = ref('')
const inputRef = ref(null)
const internalSelectedValues = ref([])
const initialSelectedValues = ref([])

// Headless UI selects the active option when the search field loses focus, so
// merely hovering an entry and then clicking somewhere else would change the
// value. The capture listener on the wrapper runs before the input's own blur
// handler and lets the setter swallow that selection.
const blurring = ref(false)

const normalizedOptions = computed(() => {
    if (Array.isArray(props.options)) return props.options
    if (typeof props.options === 'object' && props.options !== null) {
        return Object.values(props.options)
    }
    return []
})

const searchKeys = computed(() => props.searchBy ?? [props.labelBy])

const filteredOptions = computed(() => {
    const needle = query.value.trim().toLowerCase()

    if (!needle) return normalizedOptions.value

    return normalizedOptions.value.filter(option =>
        searchKeys.value.some(key => String(option[key] ?? '').toLowerCase().includes(needle))
    )
})

const selected = computed({
    get() {
        if (props.multiple) {
            if (!Array.isArray(props.modelValue)) return []
            return normalizedOptions.value.filter(option => props.modelValue.includes(option[props.trackBy]))
        } else {
            return (normalizedOptions.value.find(option => option[props.trackBy] == props.modelValue) ?? null)
        }
    },
    set(val) {
        if (blurring.value) return

        if (props.multiple) {
            const ids = val.map(v => v[props.trackBy])
            internalSelectedValues.value = ids
            emit('update:modelValue', ids)
        } else {
            const id = val?.[props.trackBy] ?? null

            if (id == props.modelValue) return

            emit('update:modelValue', id)
            emit('change', { name: props.name ?? props.id, value: id })
        }
    }
})

const onBlurCapture = () => {
    blurring.value = true
    nextTick(() => blurring.value = false)
}

watch(() => props.modelValue, val => {
    if (props.multiple && Array.isArray(val)) {
        internalSelectedValues.value = [...val]
    }
})

watch(open, (val) => {
    if (!val) query.value = ''

    // The search field only mounts with the panel, so the focus Headless UI
    // hands over on open can arrive before it exists.
    if (val) nextTick(() => inputRef.value?.$el?.focus())

    if (val && props.multiple) {
        initialSelectedValues.value = [...internalSelectedValues.value]
    }

    if (!val && props.multiple) {
        const hasChanged = JSON.stringify(initialSelectedValues.value) !== JSON.stringify(internalSelectedValues.value)

        if (hasChanged) {
            emit('change', { name: props.name ?? props.id, value: internalSelectedValues.value })
        }
    }
})
</script>

<template>
    <Combobox
        :modelValue="selected"
        @update:modelValue="selected = $event"
        :multiple="multiple"
        as="div"
        v-slot="{ open: isOpen }"
    >
        <div ref="dropdownContainer" class="relative" @blur.capture="onBlurCapture">
            <!-- Headless UI hardcodes tabindex="-1" on the button because it expects the
                 input to be the trigger, but ours lives inside the panel. -->
            <ComboboxButton
                :id="id"
                tabindex="0"
                :class="['relative flex items-center gap-2 cursor-default rounded-md border bg-white dark:bg-dark-900 py-2 pl-3 pr-10 text-left shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 border-gray-300 dark:border-dark-700 text-gray-900 dark:text-dark-200', props.class]"
            >
                <slot name="prefix" :option="selected" />
                <span class="min-w-0 flex-1 truncate">
                  <template v-if="multiple">
                    {{ selected.length ? selected.map(o => o[labelBy]).join(', ') : 'Select...' }}
                  </template>
                  <template v-else>
                    {{ selected?.[labelBy] ?? 'Select...' }}
                  </template>
                </span>
                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                  <ChevronUpDownIcon class="size-5 text-gray-400" aria-hidden="true" />
                </span>
            </ComboboxButton>

            <ComboboxOptions as="div" :class="[normalizedOptions.length > 20 ? 'max-h-[min(30rem,70vh)]' : '', props.optionsClass, dropUp ? 'bottom-full mb-1' : 'mt-1']" class="absolute z-10 w-full overflow-auto rounded-md bg-white dark:bg-dark-800 shadow-lg ring-1 ring-black/5 focus:outline-none">
                <!-- The panel swallows mousedown to keep the input focused, which would
                     also stop clicks from placing the caret inside the search field. -->
                <div class="sticky top-0 z-10 flex items-center gap-2 border-b border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-800 px-3 py-2" @mousedown.stop>
                    <SearchIcon class="size-4 shrink-0 text-gray-400" aria-hidden="true" />
                    <ComboboxInput
                        ref="inputRef"
                        class="w-full min-w-0 border-0 bg-transparent p-0 text-gray-900 dark:text-dark-200 placeholder-gray-400 focus:outline-none focus:ring-0"
                        autocomplete="off"
                        placeholder="Search..."
                        aria-label="Search options"
                        @change="query = $event.target.value"
                    />
                </div>

                <div class="py-1">
                    <div v-if="!filteredOptions.length" class="py-2 pl-3 pr-9 text-gray-500 dark:text-dark-400">
                        No results
                    </div>
                    <ComboboxOption
                        v-for="option in filteredOptions"
                        :key="option[trackBy]"
                        :value="option"
                        v-slot="{ active, selected: isSelected }"
                        as="template"
                    >
                        <div :class="[active ? 'bg-indigo-500 text-white' : 'text-gray-900 dark:text-dark-200', 'relative cursor-default select-none py-2 pl-3 pr-9']">
                            <span :class="[isSelected ? 'font-semibold' : 'font-normal', 'block truncate']">
                              <slot name="option" :option="option">
                                {{ option[labelBy] }}
                              </slot>
                            </span>
                            <span v-if="isSelected && multiple" :class="[active ? 'text-white' : 'text-indigo-500', 'absolute inset-y-0 right-0 flex items-center pr-4']">
                              <CheckIcon class="size-5" aria-hidden="true" />
                            </span>
                        </div>
                    </ComboboxOption>
                </div>
            </ComboboxOptions>
        </div>

        <!-- track open state for dropdown close detection -->
        <template v-if="isOpen">
            <span v-show="false">{{ open = true }}</span>
        </template>
        <template v-else>
            <span v-show="false">{{ open = false }}</span>
        </template>
    </Combobox>
</template>
