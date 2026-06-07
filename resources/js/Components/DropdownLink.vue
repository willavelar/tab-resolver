<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    variant: {
        type: String,
        default: 'default', // 'default' | 'danger'
    },
});

const variantClasses = computed(() =>
    props.variant === 'danger'
        ? 'text-error hover:bg-error/10'
        : 'text-body hover:bg-canvas-soft hover:text-ink',
);
</script>

<template>
    <Link
        :href="href"
        :class="[
            'block w-full px-4 py-2 text-start text-sm leading-5 transition duration-150 ease-in-out focus:bg-canvas-soft focus:outline-none',
            variantClasses,
        ]"
    >
        <slot />
    </Link>
</template>
