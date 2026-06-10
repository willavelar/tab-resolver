<!-- resources/js/Pages/Dashboard.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    sessions: {
        type: Array,
        default: () => [],
    },
});

const statusLabels = {
    pending: 'Aguardando',
    processing: 'Processando',
    completed: 'Concluída',
    needs_clarification: 'Aguarda respostas',
    failed: 'Falhou',
};

const statusClasses = {
    pending: 'bg-surface-muted text-muted',
    processing: 'bg-amber-100 text-amber-700',
    completed: 'bg-emerald-100 text-emerald-700',
    needs_clarification: 'bg-amber-100 text-amber-700',
    failed: 'bg-red-100 text-red-700',
};
</script>

<template>
    <Head title="Minhas Sessões" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold tracking-tight text-ink">Minhas Sessões</h2>
                <Link
                    :href="route('sessions.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-on-primary transition-colors hover:bg-primary-active"
                >
                    + Nova Sessão
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Lista de sessões -->
                <ul v-if="sessions.length" class="space-y-3">
                    <li v-for="session in sessions" :key="session.id">
                        <Link
                            :href="route('sessions.show', session.id)"
                            class="flex items-center justify-between gap-4 rounded-lg border border-hairline bg-surface-card px-5 py-4 transition-colors hover:border-primary"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-base font-medium text-ink">
                                    {{ session.title }}
                                </p>
                                <p class="mt-0.5 text-sm text-muted">
                                    {{ session.created_at }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="statusClasses[session.status]"
                            >
                                {{ statusLabels[session.status] }}
                            </span>
                        </Link>
                    </li>
                </ul>

                <!-- Estado vazio -->
                <div
                    v-else
                    class="rounded-lg border border-hairline bg-surface-card p-12 text-center"
                >
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
