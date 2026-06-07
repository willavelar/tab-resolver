<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
    variant: {
        type: String,
        default: 'default', // 'default' | 'danger'
    },
});

const classes = computed(() => {
    const base =
        'block w-full ps-3 pe-4 py-2 border-l-4 text-start text-base font-medium focus:outline-none transition duration-150 ease-in-out';

    if (props.variant === 'danger') {
        return `${base} border-transparent text-error hover:bg-error/10 hover:border-error`;
    }

    return props.active
        ? `${base} border-primary text-primary bg-surface-strong`
        : `${base} border-transparent text-body hover:text-ink hover:bg-canvas-soft hover:border-hairline-strong`;
});
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
