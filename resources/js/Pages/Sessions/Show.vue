<!-- resources/js/Pages/Sessions/Show.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    session: {
        type: Object,
        required: true,
    },
});

const shareUrl = computed(() => route('sessions.show', props.session.id));

const copied = ref(false);
const canShare = ref(false);
const extracting = ref(false);

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

const triggerExtraction = () => {
    router.post(
        route('sessions.extract', props.session.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (extracting.value = true),
            onFinish: () => (extracting.value = false),
        },
    );
};

const brl = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
        Number(value ?? 0),
    );

let channel = null;

const channelName = `bill-session.${props.session.id}`;

const subscribe = () => {
    if (!window.Echo || channel) {
        return;
    }
    channel = window.Echo.private(channelName);
    channel.listen('.extraction.updated', () => {
        router.reload({ only: ['session'] });
    });
};

onMounted(() => {
    canShare.value = typeof navigator !== 'undefined' && !!navigator.share;
    if (props.session.status === 'processing') {
        subscribe();
    }
});

onBeforeUnmount(() => {
    if (channel) {
        window.Echo.leave(channelName);
        channel = null;
    }
});
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

                    <div class="mt-6">
                        <!-- pending -->
                        <div
                            v-if="session.status === 'pending'"
                            class="rounded-md border border-hairline bg-surface-strong p-4 text-center"
                        >
                            <p class="text-sm text-body">Pronto para ler os itens desta conta com IA.</p>
                            <button
                                type="button"
                                class="mt-3 inline-flex items-center justify-center gap-1.5 rounded-md border border-transparent bg-primary px-[18px] py-2.5 text-sm font-medium text-on-primary transition-colors duration-150 hover:bg-primary-active focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-50"
                                :disabled="extracting"
                                @click="triggerExtraction"
                            >
                                ✨ Ler conta com IA
                            </button>
                        </div>

                        <!-- processing -->
                        <div
                            v-else-if="session.status === 'processing'"
                            class="flex items-center justify-center gap-3 rounded-md border border-hairline bg-surface-strong p-4"
                        >
                            <svg class="h-5 w-5 animate-spin text-primary" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            <p class="text-sm text-body">Lendo a conta... isso pode levar alguns segundos.</p>
                        </div>

                        <!-- failed -->
                        <div
                            v-else-if="session.status === 'failed'"
                            class="rounded-md border border-error bg-surface-strong p-4 text-center"
                        >
                            <p class="text-sm text-error">
                                Não foi possível ler a conta{{ session.failure_reason ? `: ${session.failure_reason}` : '' }}.
                            </p>
                            <button
                                type="button"
                                class="mt-3 inline-flex items-center justify-center gap-1.5 rounded-md border border-hairline-strong bg-surface-card px-[17px] py-2.5 text-sm font-medium text-ink transition-colors duration-150 hover:bg-canvas-soft focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-50"
                                :disabled="extracting"
                                @click="triggerExtraction"
                            >
                                Tentar novamente
                            </button>
                        </div>

                        <!-- completed -->
                        <div v-else-if="session.status === 'completed'">
                            <h3 class="text-sm font-semibold text-ink">Itens da conta</h3>
                            <div class="mt-3 overflow-hidden rounded-md border border-hairline">
                                <table class="w-full text-sm">
                                    <thead class="bg-surface-strong text-muted">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-medium">Item</th>
                                            <th class="px-3 py-2 text-right font-medium">Qtd</th>
                                            <th class="px-3 py-2 text-right font-medium">Unit.</th>
                                            <th class="px-3 py-2 text-right font-medium">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="item in session.items"
                                            :key="item.id"
                                            class="border-t border-hairline"
                                        >
                                            <td class="px-3 py-2 text-ink">{{ item.name }}</td>
                                            <td class="px-3 py-2 text-right text-body">{{ Number(item.quantity) }}</td>
                                            <td class="px-3 py-2 text-right text-body">{{ brl(item.unit_price) }}</td>
                                            <td class="px-3 py-2 text-right text-body">{{ brl(item.total_price) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="border-t border-hairline-strong">
                                        <tr>
                                            <td class="px-3 py-2 text-right text-muted" colspan="3">Subtotal</td>
                                            <td class="px-3 py-2 text-right text-body">{{ brl(session.subtotal) }}</td>
                                        </tr>
                                        <tr>
                                            <td class="px-3 py-2 text-right text-muted" colspan="3">Taxa de serviço</td>
                                            <td class="px-3 py-2 text-right text-body">{{ brl(session.service_charge) }}</td>
                                        </tr>
                                        <tr>
                                            <td class="px-3 py-2 text-right font-semibold text-ink" colspan="3">Total</td>
                                            <td class="px-3 py-2 text-right font-semibold text-ink">{{ brl(session.total) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
