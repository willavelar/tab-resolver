<!-- resources/js/Pages/Sessions/Show.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    session: {
        type: Object,
        required: true,
    },
});

const shareUrl = computed(() => route('sessions.show', props.session.id));

const copied = ref(false);
const canShare = ref(false);

onMounted(() => {
    canShare.value = typeof navigator !== 'undefined' && !!navigator.share;
});

const copyLink = async () => {
    try {
        await navigator.clipboard.writeText(shareUrl.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
};

const shareLink = async () => {
    try {
        await navigator.share({
            title: props.session.title,
            text: `Bora dividir a conta "${props.session.title}"?`,
            url: shareUrl.value,
        });
    } catch {
        // Usuário cancelou a folha de compartilhamento — ignorado.
    }
};
</script>

<template>
    <Head :title="session.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold tracking-tight text-ink">{{ session.title }}</h2>
                <Link
                    :href="route('dashboard')"
                    class="inline-flex items-center justify-center rounded-md border border-hairline-strong bg-surface-card px-[17px] py-2.5 text-sm font-medium text-ink transition-colors duration-150 hover:bg-canvas-soft focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas"
                >
                    ← Voltar
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-lg mx-auto sm:px-6 lg:px-8">
                <div class="rounded-lg border border-hairline bg-surface-card p-8">
                    <p class="text-sm text-muted">Criada em {{ session.created_at }}</p>

                    <div class="mt-6 rounded-md border border-hairline bg-surface-strong p-4">
                        <span class="text-sm font-medium text-body">Link da sessão</span>

                        <input
                            type="text"
                            :value="shareUrl"
                            readonly
                            class="mt-2 block w-full rounded-md border border-hairline bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                            @focus="$event.target.select()"
                        />

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 rounded-md border border-hairline-strong bg-surface-card px-[17px] py-2 text-sm font-medium text-ink transition-colors duration-150 hover:bg-canvas-soft focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas"
                                @click="copyLink"
                            >
                                {{ copied ? '✓ Copiado!' : '📋 Copiar' }}
                            </button>

                            <button
                                v-if="canShare"
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 rounded-md border border-transparent bg-primary px-[17px] py-2 text-sm font-medium text-on-primary transition-colors duration-150 hover:bg-primary-active focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas"
                                @click="shareLink"
                            >
                                🔗 Compartilhar
                            </button>
                        </div>
                    </div>

                    <div class="mt-6 overflow-hidden rounded-lg border border-hairline">
                        <img
                            :src="session.image_url"
                            :alt="`Foto da conta — ${session.title}`"
                            class="block w-full object-contain"
                        />
                    </div>

                    <div class="mt-6 rounded-md border border-hairline bg-surface-strong p-4">
                        <p class="text-sm text-body">
                            ✨ Em breve a IA irá ler os itens desta conta automaticamente.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
