<!-- resources/js/Pages/Sessions/Show.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    session: {
        type: Object,
        required: true,
    },
});

const participants = ref([...(props.session.participants ?? [])]);
const publicCopied = ref(false);

const copyPublicLink = async () => {
    try {
        await navigator.clipboard.writeText(props.session.public_url);
        publicCopied.value = true;
        setTimeout(() => (publicCopied.value = false), 2000);
    } catch {
        publicCopied.value = false;
    }
};

const extracting = ref(false);

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

const clarifyForm = useForm({ answers: {} });

const allClarificationsAnswered = computed(() =>
    (props.session.clarifications?.pending ?? []).every(
        (q) => `${clarifyForm.answers[q.id] ?? ''}`.trim() !== '',
    ),
);

const submitClarifications = () => {
    clarifyForm.post(route('sessions.clarify', props.session.id), {
        preserveScroll: true,
        onSuccess: () => clarifyForm.reset(),
    });
};

const brl = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
        Number(value ?? 0),
    );

const foodItems = computed(() =>
    (props.session.items ?? []).filter((i) => i.category === 'food'),
);
const drinkItems = computed(() =>
    (props.session.items ?? []).filter((i) => i.category === 'drink'),
);

// --- Bill analysis (split calculation) ---
const analyzeForm = useForm({});
const analysisClarifyForm = useForm({ answers: {} });

const canAnalyze = computed(
    () =>
        props.session.status === 'completed' &&
        participants.value.length >= 1 &&
        !['processing', 'needs_clarification'].includes(props.session.analysis_status),
);

const allAnalysisAnswered = computed(() =>
    (props.session.analysis_clarifications?.pending ?? []).every(
        (q) => `${analysisClarifyForm.answers[q.id] ?? ''}`.trim() !== '',
    ),
);

const analysisParticipants = computed(
    () => props.session.analysis_result?.participants ?? [],
);

const analysisGrandTotal = computed(() =>
    analysisParticipants.value.reduce((sum, p) => sum + Number(p.total ?? 0), 0),
);

const setFoodShared = (value) => {
    if (value === props.session.food_shared) {
        return;
    }

    router.patch(
        route('sessions.food-shared', props.session.id),
        { food_shared: value },
        { preserveScroll: true },
    );
};

const runAnalysis = () => {
    analyzeForm.post(route('sessions.analyze', props.session.id), {
        preserveScroll: true,
    });
};

const submitAnalysisClarification = () => {
    analysisClarifyForm.post(route('sessions.analyze.clarify', props.session.id), {
        preserveScroll: true,
        onSuccess: () => analysisClarifyForm.reset(),
    });
};

// --- Watchdog: rescues a session orphaned in "processing" when the queue dies
// without ever broadcasting a result (worker killed, OOM, queue down). Each
// threshold sits just above the matching job's server-side $timeout, so a
// slow-but-healthy run is never flagged. On firing we POST a timeout endpoint
// that flips the stuck status to "failed" and re-broadcasts, reusing the existing
// failed UI + retry button.
const EXTRACTION_TIMEOUT_MS = 150_000; // job $timeout is 120s
const ANALYSIS_TIMEOUT_MS = 210_000; // job $timeout is 180s

let extractionWatchdog = null;
let analysisWatchdog = null;

const armWatchdog = (status, timer, ms, routeName) => {
    clearTimeout(timer);
    if (status !== 'processing') {
        return null;
    }
    return setTimeout(() => {
        router.post(route(routeName, props.session.id), {}, { preserveScroll: true });
    }, ms);
};

watch(
    () => props.session.status,
    (status) => {
        extractionWatchdog = armWatchdog(
            status,
            extractionWatchdog,
            EXTRACTION_TIMEOUT_MS,
            'sessions.extract.timeout',
        );
    },
    { immediate: true },
);

watch(
    () => props.session.analysis_status,
    (status) => {
        analysisWatchdog = armWatchdog(
            status,
            analysisWatchdog,
            ANALYSIS_TIMEOUT_MS,
            'sessions.analyze.timeout',
        );
    },
    { immediate: true },
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
    channel.listen('.analysis.updated', () => {
        router.reload({ only: ['session'] });
    });
    channel.listen('.participant.submitted', (payload) => {
        if (!participants.value.some((p) => p.id === payload.id)) {
            participants.value.push(payload);
        }
    });
};

onMounted(() => {
    subscribe();
});

onBeforeUnmount(() => {
    clearTimeout(extractionWatchdog);
    clearTimeout(analysisWatchdog);
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
            <div class="max-w-lg mx-auto px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-hairline bg-surface-card p-5 sm:p-8">
                    <p class="text-sm text-muted">Criada em {{ session.created_at }}</p>

                    <div class="mt-6 rounded-md border border-hairline bg-surface-strong p-4">
                        <span class="text-base font-medium text-body">Link público (sem login)</span>
                        <p class="mt-1 text-sm text-muted">
                            Compartilhe com quem estava na mesa para enviar nome e o que consumiu.
                        </p>

                        <input
                            type="text"
                            :value="session.public_url"
                            readonly
                            class="mt-2 block w-full rounded-md border border-hairline bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                            @focus="$event.target.select()"
                        />

                        <button
                            type="button"
                            class="mt-3 inline-flex w-full sm:w-auto items-center justify-center gap-1.5 rounded-md border border-hairline-strong bg-surface-card px-5 py-3 text-base font-medium text-ink transition-colors duration-150 hover:bg-canvas-soft focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas"
                            @click="copyPublicLink"
                        >
                            {{ publicCopied ? '✓ Copiado!' : '📋 Copiar link público' }}
                        </button>
                    </div>

                    <div class="mt-6">
                        <h3 class="text-base font-semibold text-ink">
                            Participantes
                            <span class="text-muted">({{ participants.length }})</span>
                        </h3>

                        <p v-if="participants.length === 0" class="mt-2 text-sm text-muted">
                            Ninguém enviou ainda. Os envios aparecem aqui em tempo real.
                        </p>

                        <ul v-else class="mt-3 space-y-2">
                            <li
                                v-for="participant in participants"
                                :key="participant.id"
                                class="rounded-md border border-hairline bg-surface-strong p-3"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-base font-medium text-ink">{{ participant.name }}</span>
                                    <span class="flex gap-1">
                                        <span
                                            v-if="participant.has_text"
                                            class="rounded-full bg-surface-card px-2 py-0.5 text-xs text-body"
                                        >texto</span>
                                        <span
                                            v-if="participant.has_audio"
                                            class="rounded-full bg-surface-card px-2 py-0.5 text-xs text-body"
                                        >áudio</span>
                                    </span>
                                </div>

                                <p v-if="participant.text" class="mt-1 text-sm text-body">
                                    {{ participant.text }}
                                </p>

                                <audio
                                    v-if="participant.audio_url"
                                    :src="participant.audio_url"
                                    controls
                                    class="mt-2 h-9 w-full"
                                />
                            </li>
                        </ul>
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
                            <p class="text-base text-body">Pronto para ler os itens desta conta com IA.</p>
                            <button
                                type="button"
                                class="mt-3 inline-flex w-full sm:w-auto items-center justify-center gap-1.5 rounded-md border border-transparent bg-primary px-5 py-3 text-base font-medium text-on-primary transition-colors duration-150 hover:bg-primary-active focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-50"
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
                            <p class="text-base text-body">Lendo a conta... isso pode levar alguns segundos.</p>
                        </div>

                        <!-- needs clarification -->
                        <div
                            v-else-if="session.status === 'needs_clarification'"
                            class="rounded-md border border-hairline bg-surface-strong p-4"
                        >
                            <p class="text-sm font-medium text-ink">A IA tem algumas dúvidas</p>
                            <p class="mt-1 text-xs text-muted">
                                Responda para concluir a leitura da conta.
                            </p>

                            <div
                                v-if="(session.clarifications?.understood?.items ?? []).length"
                                class="mt-4 rounded-md border border-hairline bg-surface-card p-3"
                            >
                                <p class="text-xs font-medium text-muted">O que a IA já entendeu até agora</p>
                                <ul class="mt-2 space-y-1 text-sm text-body">
                                    <li
                                        v-for="(item, idx) in session.clarifications.understood.items"
                                        :key="idx"
                                        class="flex justify-between gap-2"
                                    >
                                        <span>{{ Number(item.quantity) }}x {{ item.name }}</span>
                                        <span class="text-muted">{{ brl(item.total_price) }}</span>
                                    </li>
                                </ul>
                                <div class="mt-2 flex justify-between gap-2 border-t border-hairline pt-2 text-sm">
                                    <span class="text-muted">Subtotal</span>
                                    <span class="text-ink">{{ brl(session.clarifications.understood.subtotal) }}</span>
                                </div>
                                <div class="flex justify-between gap-2 text-sm font-medium">
                                    <span class="text-muted">Total</span>
                                    <span class="text-ink">{{ brl(session.clarifications.understood.total) }}</span>
                                </div>
                            </div>

                            <form class="mt-4 space-y-4" @submit.prevent="submitClarifications">
                                <div
                                    v-for="question in (session.clarifications?.pending ?? [])"
                                    :key="question.id"
                                >
                                    <p class="text-sm text-body">{{ question.prompt }}</p>

                                    <div v-if="question.type === 'choice'" class="mt-2 flex flex-wrap gap-2">
                                        <button
                                            v-for="option in question.options"
                                            :key="option"
                                            type="button"
                                            class="rounded-md border px-4 py-2.5 text-base transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary"
                                            :class="clarifyForm.answers[question.id] === option
                                                ? 'border-primary bg-primary text-on-primary'
                                                : 'border-hairline-strong bg-surface-card text-ink hover:bg-canvas-soft'"
                                            @click="clarifyForm.answers[question.id] = option"
                                        >
                                            {{ option }}
                                        </button>
                                    </div>

                                    <input
                                        v-else
                                        v-model="clarifyForm.answers[question.id]"
                                        type="text"
                                        class="mt-2 block w-full rounded-md border border-hairline bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                                        placeholder="Sua resposta"
                                    />
                                </div>

                                <button
                                    type="submit"
                                    class="inline-flex w-full sm:w-auto items-center justify-center gap-1.5 rounded-md border border-transparent bg-primary px-5 py-3 text-base font-medium text-on-primary transition-colors duration-150 hover:bg-primary-active focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-50"
                                    :disabled="clarifyForm.processing || !allClarificationsAnswered"
                                >
                                    Enviar respostas
                                </button>
                            </form>
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
                                class="mt-3 inline-flex w-full sm:w-auto items-center justify-center gap-1.5 rounded-md border border-hairline-strong bg-surface-card px-5 py-3 text-base font-medium text-ink transition-colors duration-150 hover:bg-canvas-soft focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-50"
                                :disabled="extracting"
                                @click="triggerExtraction"
                            >
                                Tentar novamente
                            </button>
                        </div>

                        <!-- completed -->
                        <div v-else-if="session.status === 'completed'">
                            <h3 class="text-base font-semibold text-ink">Itens da conta</h3>

                            <div
                                v-for="group in [
                                    { title: 'Comida', items: foodItems },
                                    { title: 'Bebida', items: drinkItems },
                                ]"
                                :key="group.title"
                            >
                                <template v-if="group.items.length">
                                    <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-muted">
                                        {{ group.title }}
                                    </h4>
                                    <div class="mt-2 overflow-hidden rounded-md border border-hairline">
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
                                                    v-for="item in group.items"
                                                    :key="item.id"
                                                    class="border-t border-hairline"
                                                >
                                                    <td class="px-3 py-2 text-ink">{{ item.name }}</td>
                                                    <td class="px-3 py-2 text-right text-body">{{ Number(item.quantity) }}</td>
                                                    <td class="px-3 py-2 text-right text-body">{{ brl(item.unit_price) }}</td>
                                                    <td class="px-3 py-2 text-right text-body">{{ brl(item.total_price) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </template>
                            </div>

                            <div class="mt-4 overflow-hidden rounded-md border border-hairline-strong">
                                <table class="w-full text-sm">
                                    <tbody>
                                        <tr>
                                            <td class="px-3 py-2 text-right text-muted">Sub-total</td>
                                            <td class="px-3 py-2 text-right text-body w-32">{{ brl(session.subtotal) }}</td>
                                        </tr>
                                        <tr v-if="Number(session.service_charge) > 0">
                                            <td class="px-3 py-2 text-right text-muted">
                                                Gorjeta<span v-if="session.service_charge_percentage"> ({{ Number(session.service_charge_percentage) }}%)</span>
                                            </td>
                                            <td class="px-3 py-2 text-right text-body">{{ brl(session.service_charge) }}</td>
                                        </tr>
                                        <tr class="border-t border-hairline">
                                            <td class="px-3 py-2 text-right font-semibold text-ink">Total</td>
                                            <td class="px-3 py-2 text-right font-semibold text-ink">{{ brl(session.total) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Bill analysis -->
                            <div class="mt-8 border-t border-hairline pt-6">
                                <h3 class="text-base font-semibold text-ink">Análise da conta</h3>

                                <div
                                    class="mt-3 flex w-full rounded-md border border-hairline bg-surface-strong p-1"
                                >
                                    <button
                                        type="button"
                                        class="flex-1 rounded px-3 py-2.5 text-sm font-medium transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="
                                            session.food_shared
                                                ? 'bg-surface-card text-ink shadow-sm'
                                                : 'text-muted hover:text-body'
                                        "
                                        :disabled="session.analysis_status === 'processing'"
                                        @click="setFoodShared(true)"
                                    >
                                        Comida compartilhada
                                    </button>
                                    <button
                                        type="button"
                                        class="flex-1 rounded px-3 py-2.5 text-sm font-medium transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="
                                            !session.food_shared
                                                ? 'bg-surface-card text-ink shadow-sm'
                                                : 'text-muted hover:text-body'
                                        "
                                        :disabled="session.analysis_status === 'processing'"
                                        @click="setFoodShared(false)"
                                    >
                                        Comida não compartilhada
                                    </button>
                                </div>

                                <p class="mt-2 text-xs text-muted">
                                    Comida não reivindicada é dividida igualmente; bebidas são sempre individuais.
                                </p>

                                <!-- trigger -->
                                <div
                                    v-if="['pending', 'failed'].includes(session.analysis_status)"
                                    class="mt-4"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex w-full sm:w-auto items-center justify-center gap-1.5 rounded-md border border-transparent bg-primary px-5 py-3 text-base font-medium text-on-primary transition-colors duration-150 hover:bg-primary-active focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-50"
                                        :disabled="!canAnalyze || analyzeForm.processing"
                                        @click="runAnalysis"
                                    >
                                        🧮 Analisar conta
                                    </button>
                                    <p v-if="session.analysis_failure_reason" class="mt-2 text-sm text-error">
                                        {{ session.analysis_failure_reason }}
                                    </p>
                                    <p v-if="participants.length < 1" class="mt-2 text-sm text-muted">
                                        Aguardando ao menos um participante enviar o que consumiu.
                                    </p>
                                </div>

                                <!-- processing -->
                                <div
                                    v-else-if="session.analysis_status === 'processing'"
                                    class="mt-4 flex items-center justify-center gap-3 rounded-md border border-hairline bg-surface-strong p-4"
                                >
                                    <svg class="h-5 w-5 animate-spin text-primary" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                    </svg>
                                    <p class="text-sm text-body">Analisando a conta...</p>
                                </div>

                                <!-- needs clarification -->
                                <div
                                    v-else-if="session.analysis_status === 'needs_clarification'"
                                    class="mt-4 rounded-md border border-hairline bg-surface-strong p-4"
                                >
                                    <p class="text-sm font-medium text-ink">Precisamos de algumas respostas</p>
                                    <p class="mt-1 text-xs text-muted">Responda para fechar a divisão da conta.</p>

                                    <div
                                        v-if="(session.analysis_clarifications?.understood?.claims ?? []).length"
                                        class="mt-4 rounded-md border border-hairline bg-surface-card p-3"
                                    >
                                        <p class="text-xs font-medium text-muted">O que a IA já entendeu até agora</p>
                                        <div
                                            v-for="(claim, idx) in session.analysis_clarifications.understood.claims"
                                            :key="idx"
                                            class="mt-2"
                                        >
                                            <p class="text-sm font-medium text-ink">{{ claim.participant_name }}</p>
                                            <ul class="mt-1 space-y-0.5 text-sm text-body">
                                                <li v-for="(item, i) in (claim.items ?? [])" :key="i">
                                                    {{ Number(item.quantity) }}x {{ item.name }}
                                                </li>
                                                <li v-if="!(claim.items ?? []).length" class="text-muted">
                                                    (nada atribuído ainda)
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                    <form class="mt-4 space-y-4" @submit.prevent="submitAnalysisClarification">
                                        <div
                                            v-for="question in (session.analysis_clarifications?.pending ?? [])"
                                            :key="question.id"
                                        >
                                            <p class="text-sm text-body">{{ question.prompt }}</p>

                                            <div v-if="question.type === 'choice'" class="mt-2 flex flex-wrap gap-2">
                                                <button
                                                    v-for="option in question.options"
                                                    :key="option"
                                                    type="button"
                                                    class="rounded-md border px-4 py-2.5 text-base transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary"
                                                    :class="analysisClarifyForm.answers[question.id] === option
                                                        ? 'border-primary bg-primary text-on-primary'
                                                        : 'border-hairline-strong bg-surface-card text-ink hover:bg-canvas-soft'"
                                                    @click="analysisClarifyForm.answers[question.id] = option"
                                                >
                                                    {{ option }}
                                                </button>
                                            </div>

                                            <input
                                                v-else
                                                v-model="analysisClarifyForm.answers[question.id]"
                                                type="text"
                                                class="mt-2 block w-full rounded-md border border-hairline bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                                                placeholder="Sua resposta"
                                            />
                                        </div>

                                        <button
                                            type="submit"
                                            class="inline-flex w-full sm:w-auto items-center justify-center gap-1.5 rounded-md border border-transparent bg-primary px-5 py-3 text-base font-medium text-on-primary transition-colors duration-150 hover:bg-primary-active focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-50"
                                            :disabled="analysisClarifyForm.processing || !allAnalysisAnswered"
                                        >
                                            Enviar respostas
                                        </button>
                                    </form>
                                </div>

                                <!-- completed -->
                                <div
                                    v-else-if="session.analysis_status === 'completed'"
                                    class="mt-4 space-y-3"
                                >
                                    <div
                                        v-for="person in analysisParticipants"
                                        :key="person.participant_id"
                                        class="rounded-md border border-hairline bg-surface-strong p-4"
                                    >
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-base font-semibold text-ink">{{ person.name }}</span>
                                            <span class="text-base font-semibold text-ink">{{ brl(person.total) }}</span>
                                        </div>
                                        <ul class="mt-2 space-y-1 text-sm text-body">
                                            <li v-for="(item, idx) in (person.items ?? [])" :key="idx">
                                                {{ Number(item.quantity) }}x {{ item.name }} — {{ brl(item.total_price) }}
                                            </li>
                                            <li v-if="Number(person.shared_food_share) > 0">
                                                Parte da comida compartilhada — {{ brl(person.shared_food_share) }}
                                            </li>
                                        </ul>
                                        <div class="mt-2 text-xs text-muted">
                                            Subtotal {{ brl(person.subtotal) }} · Gorjeta {{ brl(person.tip) }}
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between border-t border-hairline pt-3">
                                        <span class="text-base font-semibold text-ink">Total</span>
                                        <span class="text-base font-semibold text-ink">{{ brl(analysisGrandTotal) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
