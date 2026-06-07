<!-- resources/js/Pages/Dashboard.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Minhas Sessões" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold tracking-tight text-ink">Minhas Sessões</h2>
                <Link
                    :href="route('sessions.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-on-primary transition-colors hover:bg-primary-active"
                >
                    + Nova Sessão
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="rounded-lg border border-hairline bg-surface-card p-12 text-center">
                    <div class="text-5xl mb-4">🧾</div>
                    <h3 class="text-lg font-medium text-ink mb-2">
                        Nenhuma sessão ainda
                    </h3>
                    <p class="text-sm text-muted mb-6 max-w-xs mx-auto">
                        Crie uma sessão, envie o link para o grupo e deixe a IA dividir a conta.
                    </p>
                    <Link
                        :href="route('sessions.create')"
                        class="inline-flex items-center gap-2 rounded-md bg-primary px-6 py-3 text-sm font-medium text-on-primary transition-colors hover:bg-primary-active"
                    >
                        Criar primeira sessão →
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
