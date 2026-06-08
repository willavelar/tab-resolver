<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { computed, ref } from 'vue';

const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    modelValue: { type: String, default: '' },
    options: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const OTHER = '__other__';

// Open in "Outro" mode when the stored value isn't one of the known options.
const isCustom = ref(
    props.modelValue !== '' && !props.options.includes(props.modelValue),
);

const selectValue = computed({
    get() {
        return isCustom.value ? OTHER : props.modelValue;
    },
    set(value) {
        if (value === OTHER) {
            isCustom.value = true;
            emit('update:modelValue', '');
            return;
        }
        isCustom.value = false;
        emit('update:modelValue', value);
    },
});

const customValue = computed({
    get() {
        return props.modelValue;
    },
    set(value) {
        emit('update:modelValue', value);
    },
});
</script>

<template>
    <div>
        <InputLabel :for="id" :value="label" />

        <select
            :id="id"
            v-model="selectValue"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        >
            <option v-for="option in options" :key="option" :value="option">
                {{ option }}
            </option>
            <option :value="OTHER">Outro…</option>
        </select>

        <TextInput
            v-if="isCustom"
            :id="`${id}_custom`"
            v-model="customValue"
            type="text"
            class="mt-2 block w-full"
            placeholder="Nome do modelo"
            autocomplete="off"
        />
    </div>
</template>
