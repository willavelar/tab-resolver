<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';

const MAX_SECONDS = 120;

const emit = defineEmits(['update:blob', 'update:duration']);

const supported = ref(
    typeof navigator !== 'undefined' &&
        !!navigator.mediaDevices &&
        typeof window !== 'undefined' &&
        'MediaRecorder' in window,
);

const recording = ref(false);
const seconds = ref(0);
const audioUrl = ref(null);

let mediaRecorder = null;
let chunks = [];
let stream = null;
let timer = null;

const label = computed(() => {
    const mm = String(Math.floor(seconds.value / 60)).padStart(2, '0');
    const ss = String(seconds.value % 60).padStart(2, '0');
    return `${mm}:${ss}`;
});

const stopTracks = () => {
    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }
};

const clearTimer = () => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
};

const startRecording = async () => {
    if (!supported.value || recording.value) {
        return;
    }

    if (audioUrl.value) {
        URL.revokeObjectURL(audioUrl.value);
        audioUrl.value = null;
    }
    emit('update:blob', null);
    emit('update:duration', 0);

    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        supported.value = false;
        return;
    }

    chunks = [];
    seconds.value = 0;
    mediaRecorder = new MediaRecorder(stream);

    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
            chunks.push(event.data);
        }
    };

    mediaRecorder.onstop = () => {
        clearTimer();
        stopTracks();
        recording.value = false;

        const blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
        audioUrl.value = URL.createObjectURL(blob);
        emit('update:blob', blob);
        emit('update:duration', seconds.value);
    };

    mediaRecorder.start();
    recording.value = true;

    timer = setInterval(() => {
        seconds.value += 1;
        if (seconds.value >= MAX_SECONDS) {
            stopRecording();
        }
    }, 1000);
};

const stopRecording = () => {
    if (mediaRecorder && recording.value) {
        mediaRecorder.stop();
    }
};

const reset = () => {
    if (audioUrl.value) {
        URL.revokeObjectURL(audioUrl.value);
        audioUrl.value = null;
    }
    seconds.value = 0;
    emit('update:blob', null);
    emit('update:duration', 0);
};

onBeforeUnmount(() => {
    clearTimer();
    stopTracks();
    if (audioUrl.value) {
        URL.revokeObjectURL(audioUrl.value);
    }
});
</script>

<template>
    <div class="rounded-md border border-hairline bg-surface-strong p-4">
        <p v-if="!supported" class="text-sm text-muted">
            Seu navegador não permite gravar áudio. Use o campo de texto.
        </p>

        <template v-else>
            <div class="flex items-center gap-3">
                <button
                    v-if="!recording"
                    type="button"
                    class="inline-flex items-center justify-center gap-1.5 rounded-md border border-transparent bg-success px-[17px] py-2 text-sm font-medium text-on-primary transition-colors duration-150 hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-success"
                    @click="startRecording"
                >
                    🎙️ Gravar
                </button>

                <button
                    v-else
                    type="button"
                    class="inline-flex items-center justify-center gap-1.5 rounded-md border border-error bg-surface-card px-[17px] py-2 text-sm font-medium text-error transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-error"
                    @click="stopRecording"
                >
                    ⏹️ Parar
                </button>

                <span class="text-sm tabular-nums text-body">{{ label }} / 02:00</span>
            </div>

            <div v-if="audioUrl" class="mt-3 flex items-center gap-3">
                <audio :src="audioUrl" controls class="h-9 w-full" />
                <button
                    type="button"
                    class="shrink-0 text-sm font-medium text-muted underline hover:text-body"
                    @click="reset"
                >
                    Remover
                </button>
            </div>
        </template>
    </div>
</template>
