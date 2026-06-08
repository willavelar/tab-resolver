<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import ModelSelect from '@/Components/ModelSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    receipt_model: { type: String, default: null },
    audio_model: { type: String, default: null },
    has_api_key: { type: Boolean, default: false },
    api_key_preview: { type: String, default: null },
    status: { type: String, default: null },
});

const receiptOptions = ['gpt-4o-mini', 'gpt-4o'];
const audioOptions = ['whisper-1', 'gpt-4o-transcribe', 'gpt-4o-mini-transcribe'];

const form = useForm({
    receipt_model: props.receipt_model ?? 'gpt-4o-mini',
    audio_model: props.audio_model ?? 'whisper-1',
    api_key: '',
});

const submit = () => {
    form.patch(route('integrations.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('api_key'),
    });
};
</script>

<template>
    <Head title="Integrações" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-ink">
                Integrações
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="bg-surface-card p-6 shadow sm:rounded-lg">
                    <header class="mb-6">
                        <h3 class="text-lg font-medium text-ink">
                            Integração com a IA (OpenAI)
                        </h3>
                        <p class="mt-1 text-sm text-muted">
                            Configure a chave da OpenAI e os modelos usados para ler os
                            recibos e transcrever áudios. A chave é armazenada de forma
                            criptografada e nunca é exibida novamente por completo.
                        </p>
                    </header>

                    <p
                        v-if="status === 'integration-updated'"
                        class="mb-4 text-sm font-medium text-green-600"
                    >
                        Integração atualizada com sucesso.
                    </p>

                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="api_key" value="Chave da API" />

                            <TextInput
                                id="api_key"
                                type="password"
                                class="mt-1 block w-full"
                                v-model="form.api_key"
                                autocomplete="off"
                                :placeholder="has_api_key ? api_key_preview : 'sk-...'"
                            />

                            <p
                                v-if="has_api_key"
                                class="mt-1 text-sm font-medium text-green-600"
                            >
                                Configurado ✓ — deixe em branco para manter a chave atual.
                            </p>

                            <InputError class="mt-2" :message="form.errors.api_key" />
                        </div>

                        <div>
                            <ModelSelect
                                id="receipt_model"
                                label="Modelo do recibo (leitura da imagem)"
                                :options="receiptOptions"
                                v-model="form.receipt_model"
                            />
                            <InputError class="mt-2" :message="form.errors.receipt_model" />
                        </div>

                        <div>
                            <ModelSelect
                                id="audio_model"
                                label="Modelo de áudio (transcrição)"
                                :options="audioOptions"
                                v-model="form.audio_model"
                            />
                            <InputError class="mt-2" :message="form.errors.audio_model" />
                        </div>

                        <div class="flex items-center gap-4">
                            <PrimaryButton :disabled="form.processing">
                                Salvar
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
