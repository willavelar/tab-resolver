<!-- resources/js/Pages/Sessions/Create.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const imageInput = ref(null);

const form = useForm({
    title: '',
    image: null,
});

const handleImage = (e) => {
    form.image = e.target.files[0];
};

const submit = () => {
    form.post(route('sessions.store'), { forceFormData: true });
};
</script>

<template>
    <Head title="Nova Sessão" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold tracking-tight text-ink">Nova Sessão</h2>
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
                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="title" value="Nome da sessão" />
                            <TextInput
                                id="title"
                                v-model="form.title"
                                type="text"
                                class="mt-1 block w-full"
                                placeholder="Ex: Jantar de quinta, Churrasco do Leo..."
                                required
                                autofocus
                            />
                            <InputError class="mt-2" :message="form.errors.title" />
                        </div>

                        <div>
                            <InputLabel for="image" value="Foto da conta" />
                            <div
                                class="mt-1 flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-hairline-strong px-6 py-10 hover:border-primary transition-colors cursor-pointer"
                                @click="imageInput.click()"
                            >
                                <template v-if="!form.image">
                                    <div class="text-4xl mb-3">📷</div>
                                    <p class="text-sm text-body">Clique para selecionar a foto da conta</p>
                                    <p class="text-xs text-muted mt-1">JPG, PNG ou HEIC</p>
                                </template>
                                <template v-else>
                                    <div class="text-4xl mb-3">✅</div>
                                    <p class="text-sm font-medium text-ink">{{ form.image.name }}</p>
                                    <p class="text-xs text-muted mt-1">Clique para trocar</p>
                                </template>
                                <input
                                    ref="imageInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/heic,image/heif"
                                    class="hidden"
                                    @change="handleImage"
                                />
                            </div>
                            <InputError class="mt-2" :message="form.errors.image" />
                        </div>

                        <div class="rounded-md border border-hairline bg-surface-strong p-4">
                            <p class="text-sm text-body">
                                ✨ A IA irá ler os itens da conta automaticamente após o upload.
                            </p>
                        </div>

                        <PrimaryButton
                            class="w-full justify-center"
                            :class="{ 'opacity-25': form.processing }"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Criando sessão...' : 'Criar Sessão →' }}
                        </PrimaryButton>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
