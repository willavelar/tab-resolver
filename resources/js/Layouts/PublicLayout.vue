<script setup>
import ThemeToggle from '@/Components/ThemeToggle.vue';
</script>

<template>
    <div class="relative flex min-h-screen flex-col items-center bg-canvas pt-6 sm:pt-12">
        <div class="absolute right-4 top-4">
            <ThemeToggle />
        </div>
        <div class="w-full max-w-lg px-4 sm:px-6">
            <slot />
        </div>
    </div>
</template>
