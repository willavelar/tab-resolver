<!-- resources/js/Pages/Auth/Login.vue -->
<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="TabResolver — Entrar" />

        <div class="mb-8 text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-ink">TabResolver</h1>
            <p class="mt-2 text-sm text-muted">Divida a conta sem discussão</p>
        </div>

        <div v-if="status" class="mb-4 text-sm font-medium text-success">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <InputLabel for="email" value="E-mail" />
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="username"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Senha" />
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-body cursor-pointer">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="rounded border-hairline-strong bg-surface-card text-primary focus:ring-primary"
                    />
                    Lembrar-me
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm text-primary hover:text-primary-active"
                >
                    Esqueceu a senha?
                </Link>
            </div>

            <PrimaryButton
                class="w-full justify-center"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
            >
                Entrar
            </PrimaryButton>

            <p class="text-center text-sm text-muted">
                Não tem conta?
                <Link :href="route('register')" class="text-primary hover:text-primary-active">
                    Registrar-se
                </Link>
            </p>
        </form>
    </GuestLayout>
</template>
