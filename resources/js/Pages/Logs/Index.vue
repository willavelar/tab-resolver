<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    files: { type: Array, default: () => [] },
});

const showing = ref(false);
const loading = ref(false);
const error = ref(null);
const current = ref({ name: '', content: '' });

const open = async (file) => {
    showing.value = true;
    loading.value = true;
    error.value = null;
    current.value = { name: file.name, content: '' };

    try {
        const { data } = await window.axios.get(route('logs.show', file.name));
        current.value = data;
    } catch (e) {
        error.value = 'Não foi possível carregar o arquivo de log.';
    } finally {
        loading.value = false;
    }
};

const close = () => {
    showing.value = false;
};

const formatSize = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};
</script>

<template>
    <Head title="Log" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-ink">Log</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <div class="bg-surface-card p-6 shadow sm:rounded-lg">
                    <header class="mb-6">
                        <h3 class="text-lg font-medium text-ink">
                            Arquivos de log
                        </h3>
                        <p class="mt-1 text-sm text-muted">
                            Arquivos gravados em <code>storage/logs</code>. Clique
                            em um arquivo para ler o conteúdo.
                        </p>
                    </header>

                    <p v-if="files.length === 0" class="text-sm text-muted">
                        Nenhum arquivo de log encontrado.
                    </p>

                    <ul v-else class="divide-y divide-hairline">
                        <li
                            v-for="file in files"
                            :key="file.name"
                            class="flex items-center justify-between gap-4 py-3"
                        >
                            <button
                                type="button"
                                class="truncate text-left text-sm font-medium text-ink hover:underline"
                                @click="open(file)"
                            >
                                {{ file.name }}
                            </button>
                            <span class="shrink-0 text-xs text-muted">
                                {{ formatSize(file.size) }} ·
                                {{ file.modified_at }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <Modal :show="showing" max-width="2xl" @close="close">
            <div class="flex flex-col">
                <div
                    class="flex items-center justify-between border-b border-hairline px-6 py-4"
                >
                    <h3 class="truncate text-base font-medium text-ink">
                        {{ current.name }}
                    </h3>
                    <SecondaryButton @click="close">Fechar</SecondaryButton>
                </div>

                <div class="max-h-[70vh] overflow-auto px-6 py-4">
                    <p v-if="loading" class="text-sm text-muted">
                        Carregando…
                    </p>
                    <p v-else-if="error" class="text-sm text-red-600">
                        {{ error }}
                    </p>
                    <pre
                        v-else
                        class="whitespace-pre-wrap break-words text-xs leading-relaxed text-body"
                        >{{ current.content }}</pre
                    >
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
