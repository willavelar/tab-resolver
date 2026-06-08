<script setup>
import AudioRecorder from '@/Components/AudioRecorder.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    session: {
        type: Object,
        required: true,
    },
    alreadySubmitted: {
        type: Boolean,
        default: false,
    },
    submittedName: {
        type: String,
        default: null,
    },
});

const sent = ref(props.alreadySubmitted);

// 'text' | 'audio' — define qual input aparece e o que é enviado.
const mode = ref('audio');

const form = useForm({
    name: '',
    text: '',
    audio: null,
    audio_duration: 0,
});

const remaining = computed(() => 256 - form.text.length);

const canSubmit = computed(() => {
    if (form.name.trim().length === 0) {
        return false;
    }

    return mode.value === 'text' ? form.text.trim().length > 0 : form.audio !== null;
});

// Ao trocar de opção, limpa o conteúdo da outra para nunca enviar os dois.
const setMode = (next) => {
    if (mode.value === next) {
        return;
    }

    mode.value = next;
    form.text = '';
    form.audio = null;
    form.audio_duration = 0;
    form.clearErrors('text', 'audio', 'audio_duration');
};

const onBlob = (blob) => {
    form.audio = blob;
};

const onDuration = (duration) => {
    form.audio_duration = duration;
};

const submit = () => {
    form
        .transform((data) => ({
            name: data.name,
            // Envia apenas o campo da opção escolhida; o outro é ignorado.
            ...(mode.value === 'text'
                ? { text: data.text }
                : { audio: data.audio ?? undefined, audio_duration: data.audio_duration }),
        }))
        .post(route('public.participants.store', props.session.token), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                sent.value = true;
                mode.value = 'audio';
                form.reset();
            },
        });
};
</script>

<template>
    <Head :title="session.title" />

    <PublicLayout>
        <div class="rounded-lg border border-hairline bg-surface-card p-6">
            <h1 class="text-xl font-semibold text-ink">{{ session.title }}</h1>
            <p class="mt-1 text-sm text-muted">Diga o que você consumiu desta conta.</p>

            <div class="mt-4 overflow-hidden rounded-lg border border-hairline">
                <img
                    :src="session.image_url"
                    :alt="`Foto da conta — ${session.title}`"
                    class="block w-full object-contain"
                />
            </div>

            <div
                v-if="sent"
                class="mt-6 rounded-md border border-hairline bg-surface-strong p-4 text-center"
            >
                <p class="text-sm text-body">
                    ✓ Enviado<span v-if="submittedName">, {{ submittedName }}</span>! Obrigado por
                    participar.
                </p>
                <p class="mt-1 text-xs text-muted">Você já participou desta conta.</p>
            </div>

            <form v-else class="mt-6 space-y-5" @submit.prevent="submit">
                <div>
                    <InputLabel for="name" value="Seu nome" />
                    <TextInput
                        id="name"
                        v-model="form.name"
                        type="text"
                        class="mt-1 block w-full"
                        autocomplete="off"
                        required
                    />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel value="Como você quer enviar?" />
                    <div
                        class="mt-1 inline-flex rounded-md border border-hairline bg-surface-strong p-1"
                    >
                        <button
                            type="button"
                            class="rounded px-4 py-1.5 text-sm font-medium transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary"
                            :class="
                                mode === 'audio'
                                    ? 'bg-surface-card text-ink shadow-sm'
                                    : 'text-muted hover:text-body'
                            "
                            @click="setMode('audio')"
                        >
                            🎙️ Áudio
                        </button>
                        <button
                            type="button"
                            class="rounded px-4 py-1.5 text-sm font-medium transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary"
                            :class="
                                mode === 'text'
                                    ? 'bg-surface-card text-ink shadow-sm'
                                    : 'text-muted hover:text-body'
                            "
                            @click="setMode('text')"
                        >
                            ✍️ Texto
                        </button>
                    </div>
                </div>

                <div v-if="mode === 'text'">
                    <InputLabel for="text" value="O que você consumiu" />
                    <textarea
                        id="text"
                        v-model="form.text"
                        maxlength="256"
                        rows="3"
                        class="mt-1 block w-full rounded-md border border-hairline bg-surface-card px-3 py-2 text-sm text-ink focus:border-primary focus:ring-primary"
                    />
                    <div class="mt-1 flex items-center justify-between">
                        <InputError :message="form.errors.text" />
                        <span class="text-xs text-muted">{{ remaining }} caracteres</span>
                    </div>
                </div>

                <div v-else>
                    <InputLabel value="Grave um áudio (até 2 min)" />
                    <div class="mt-1">
                        <AudioRecorder @update:blob="onBlob" @update:duration="onDuration" />
                    </div>
                    <InputError class="mt-2" :message="form.errors.audio" />
                    <InputError class="mt-2" :message="form.errors.audio_duration" />
                </div>

                <PrimaryButton class="w-full" :disabled="!canSubmit || form.processing">
                    Enviar
                </PrimaryButton>
            </form>
        </div>
    </PublicLayout>
</template>
