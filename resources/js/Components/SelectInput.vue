<script setup>
import {computed, ref, watch} from 'vue'
import {Listbox, ListboxButton, ListboxOption, ListboxOptions} from '@headlessui/vue'
import SearchableSelectInput from '@/Components/SearchableSelectInput.vue'
import ChevronUpDownIcon from 'bootstrap-icons/icons/chevron-expand.svg?component'
import CheckIcon from 'bootstrap-icons/icons/check2.svg?component'
import {useDropUp} from '@/Composables/useDropUp.js'

const SEARCH_THRESHOLD = 15

// The searchable variant turns the trigger into a text field, which would pop
// the on-screen keyboard on every tap, so touch devices keep the plain listbox.
const isCoarsePointer = typeof window !== 'undefined'
    && window.matchMedia?.('(hover: none) and (pointer: coarse)').matches === true

const props = defineProps({
    modelValue: [Array, Object, String, Number, null],
    options: { type: [Array, Object], required: true },
    multiple: { type: Boolean, default: false },
    trackBy: { type: String, default: 'id' },
    labelBy: { type: String, default: 'name' },
    searchable: { type: [Boolean, String], default: 'auto' },
    searchBy: { type: Array, default: null },
    class: { type: String, default: '' },
    optionsClass: { type: String, default: '' },
    name: { type: String, default: '' },
    id: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'change'])

const open = ref(false)
const {container: dropdownContainer, dropUp} = useDropUp(open)
const internalSelectedValues = ref([])
const initialSelectedValues = ref([])

const normalizedOptions = computed(() => {
    if (Array.isArray(props.options)) return props.options
    if (typeof props.options === 'object' && props.options !== null) {
        return Object.values(props.options)
    }
    return []
})

const useSearch = computed(() => {
    if (props.searchable !== 'auto') return props.searchable === true

    return !isCoarsePointer && normalizedOptions.value.length > SEARCH_THRESHOLD
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
        if (props.multiple) {
            const ids = val.map(v => v[props.trackBy])
            internalSelectedValues.value = ids
            emit('update:modelValue', ids)
        } else {
            const id = val?.[props.trackBy] ?? null
            emit('update:modelValue', id)
            emit('change', { name: props.name ?? props.id, value: id })
        }
    }
})

watch(() => props.modelValue, val => {
    if (props.multiple && Array.isArray(val)) {
        internalSelectedValues.value = [...val]
    }
})

watch(open, (val) => {
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
    <SearchableSelectInput
        v-if="useSearch"
        :modelValue="modelValue"
        :options="options"
        :multiple="multiple"
        :trackBy="trackBy"
        :labelBy="labelBy"
        :searchBy="searchBy"
        :class="props.class"
        :optionsClass="optionsClass"
        :name="name"
        :id="id"
        @update:modelValue="emit('update:modelValue', $event)"
        @change="emit('change', $event)"
    >
        <template #prefix="slotProps">
            <slot name="prefix" v-bind="slotProps" />
        </template>
        <template #option="slotProps">
            <slot name="option" v-bind="slotProps">{{ slotProps.option[labelBy] }}</slot>
        </template>
    </SearchableSelectInput>

    <Listbox
        v-else
        :modelValue="selected"
        @update:modelValue="selected = $event"
        :multiple="multiple"
        as="div"
        v-slot="{ open: isOpen }"
    >
        <div ref="dropdownContainer" class="relative">
            <ListboxButton :class="['relative flex items-center gap-2 cursor-default rounded-md border bg-white dark:bg-dark-900 py-2 pl-3 pr-10 text-left shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 border-gray-300 dark:border-dark-700 text-gray-900 dark:text-dark-200', props.class]">
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
            </ListboxButton>

            <ListboxOptions :class="[normalizedOptions.length > 20 ? 'max-h-[min(30rem,70vh)]' : '', props.optionsClass, dropUp ? 'bottom-full mb-1' : 'mt-1']" class="absolute z-10 w-full overflow-auto rounded-md bg-white dark:bg-dark-800 py-1 shadow-lg ring-1 ring-black/5 focus:outline-none">
                <ListboxOption
                    v-for="option in normalizedOptions"
                    :key="option[trackBy]"
                    :value="option"
                    v-slot="{ active, selected: isSelected }"
                    as="template"
                >
                    <li :class="[active ? 'bg-indigo-500 text-white' : 'text-gray-900 dark:text-dark-200', 'relative cursor-default select-none py-2 pl-3 pr-9']">
                        <span :class="[isSelected ? 'font-semibold' : 'font-normal', 'block truncate']">
                          <slot name="option" :option="option">
                            {{ option[labelBy] }}
                          </slot>
                        </span>
                        <span v-if="isSelected && multiple" :class="[active ? 'text-white' : 'text-indigo-500', 'absolute inset-y-0 right-0 flex items-center pr-4']">
                          <CheckIcon class="h-5 w-5" aria-hidden="true" />
                        </span>
                    </li>
                </ListboxOption>
            </ListboxOptions>
        </div>

        <!-- track open state for dropdown close detection -->
        <template v-if="isOpen">
            <span v-show="false">{{ open = true }}</span>
        </template>
        <template v-else>
            <span v-show="false">{{ open = false }}</span>
        </template>
    </Listbox>
</template>

