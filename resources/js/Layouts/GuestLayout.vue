<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="relative flex min-h-screen flex-col items-center bg-canvas pt-6 sm:justify-center sm:pt-0"
    >
        <div class="absolute right-4 top-4">
            <ThemeToggle />
        </div>

        <div>
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-ink" />
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden rounded-lg border border-hairline bg-surface-card px-6 py-8 sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
