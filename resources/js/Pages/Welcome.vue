<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
    laravelVersion: { type: String, required: true },
    phpVersion: { type: String, required: true },
});
</script>

<template>
    <Head title="TabResolver — Divida a conta sem discussão" />

    <div class="min-h-screen bg-canvas text-body">
        <!-- Top nav -->
        <header class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
            <div class="flex items-center gap-2">
                <ApplicationLogo class="h-7 w-7 fill-current text-ink" />
                <span class="text-base font-semibold tracking-tight text-ink">TabResolver</span>
            </div>
            <nav class="flex items-center gap-3">
                <ThemeToggle />
                <Link
                    v-if="canLogin"
                    :href="route('login')"
                    class="text-sm font-medium text-body transition-colors hover:text-ink"
                >
                    Entrar
                </Link>
                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="inline-flex items-center rounded-md bg-primary px-[18px] py-2.5 text-sm font-medium text-on-primary transition-colors hover:bg-primary-active"
                >
                    Criar conta
                </Link>
            </nav>
        </header>

        <!-- Hero -->
        <main class="mx-auto max-w-6xl px-6">
            <section class="py-20 sm:py-28">
                <span class="inline-block rounded-pill bg-surface-strong px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.088em] text-ink">
                    Dividir a conta, resolvido
                </span>
                <h1 class="mt-6 max-w-3xl text-5xl font-normal leading-tight tracking-tight text-ink sm:text-7xl">
                    Divida a conta do rolê sem discussão.
                </h1>
                <p class="mt-6 max-w-xl text-lg leading-relaxed text-body">
                    Tire uma foto da conta. A IA lê os itens automaticamente. Cada um
                    marca o que consumiu e o TabResolver calcula quem deve quanto.
                </p>
                <div class="mt-10 flex flex-wrap items-center gap-4">
                    <Link
                        :href="canRegister ? route('register') : route('login')"
                        class="inline-flex items-center rounded-md bg-primary px-5 py-3 text-sm font-medium text-on-primary transition-colors hover:bg-primary-active"
                    >
                        Começar agora →
                    </Link>
                    <Link
                        v-if="canLogin"
                        :href="route('login')"
                        class="text-sm font-medium text-ink transition-colors hover:text-primary"
                    >
                        Já tenho conta
                    </Link>
                </div>
            </section>

            <!-- Feature row -->
            <section class="grid gap-4 pb-24 sm:grid-cols-3">
                <article class="rounded-lg border border-hairline bg-surface-card p-6">
                    <div class="text-3xl">📷</div>
                    <h3 class="mt-4 text-lg font-semibold text-ink">Foto da conta</h3>
                    <p class="mt-2 text-sm leading-relaxed text-body">
                        Suba uma foto do recibo e pronto — nada de digitar item por item.
                    </p>
                </article>
                <article class="rounded-lg border border-hairline bg-surface-card p-6">
                    <div class="text-3xl">🤖</div>
                    <h3 class="mt-4 text-lg font-semibold text-ink">IA lê os itens</h3>
                    <p class="mt-2 text-sm leading-relaxed text-body">
                        Reconhecimento automático dos itens e valores direto do recibo.
                    </p>
                </article>
                <article class="rounded-lg border border-hairline bg-surface-card p-6">
                    <div class="text-3xl">🧮</div>
                    <h3 class="mt-4 text-lg font-semibold text-ink">Cada um paga o seu</h3>
                    <p class="mt-2 text-sm leading-relaxed text-body">
                        Participantes marcam o que consumiram e o total sai na hora.
                    </p>
                </article>
            </section>
        </main>

        <!-- Footer -->
        <footer class="border-t border-hairline">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-6 py-8 text-sm text-muted sm:flex-row">
                <span>TabResolver</span>
                <span class="font-mono text-xs">Laravel v{{ laravelVersion }} · PHP v{{ phpVersion }}</span>
            </div>
        </footer>
    </div>
</template>
