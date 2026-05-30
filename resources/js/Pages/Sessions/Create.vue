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
    form.post('/sessions', { forceFormData: true });
};
</script>

<template>
    <Head title="Nova Sessão" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link
                    :href="route('dashboard')"
                    class="text-gray-400 hover:text-gray-600 transition-colors"
                >
                    ← Voltar
                </Link>
                <h2 class="text-xl font-semibold text-gray-800">Nova Sessão</h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-lg mx-auto sm:px-6 lg:px-8">
                <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-8">
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
                                class="mt-1 flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 px-6 py-10 hover:border-indigo-400 transition-colors cursor-pointer"
                                @click="imageInput.click()"
                            >
                                <template v-if="!form.image">
                                    <div class="text-4xl mb-3">📷</div>
                                    <p class="text-sm text-gray-600">Clique para selecionar a foto da conta</p>
                                    <p class="text-xs text-gray-400 mt-1">JPG, PNG ou HEIC</p>
                                </template>
                                <template v-else>
                                    <div class="text-4xl mb-3">✅</div>
                                    <p class="text-sm font-medium text-gray-700">{{ form.image.name }}</p>
                                    <p class="text-xs text-gray-400 mt-1">Clique para trocar</p>
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

                        <div class="rounded-md bg-indigo-50 p-4">
                            <p class="text-sm text-indigo-700">
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
