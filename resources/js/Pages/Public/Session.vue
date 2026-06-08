<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    session: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head :title="session.title" />

    <PublicLayout>
        <h1 class="text-xl font-semibold text-ink">{{ session.title }}</h1>
    </PublicLayout>
</template>
