# TabResolver Phase 1 — Auth & Protected Routes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scaffold Laravel 13 + Docker Compose, configurar autenticação com Laravel Breeze (Inertia + Vue 3), definir login como tela inicial e proteger /dashboard e /sessions/create com o middleware `auth`.

**Architecture:** Docker Compose com 4 serviços: `app` (PHP 8.3-FPM), `nginx` (web server), `mysql` (MySQL 8), `redis` (Redis 7). Laravel Breeze scaffolda autenticação. Inertia.js + Vue 3 renderiza páginas guiadas pelo servidor sem API separada. Root `/` redireciona para /login (sem auth) ou /dashboard (com auth). Pest para testes de feature.

**Tech Stack:** Laravel 13, PHP 8.3, Inertia.js 2.x, Vue 3, Tailwind CSS (via Breeze), Vite, Pest 3, Docker Compose, MySQL 8, Redis 7, Nginx Alpine.

---

## File Map

**Criar:**
- `docker/php/Dockerfile`
- `docker/nginx/default.conf`
- `docker-compose.yml`
- `app/Http/Controllers/SessionController.php`
- `resources/js/Pages/Sessions/Create.vue`
- `tests/Feature/Auth/RouteProtectionTest.php`

**Modificar (após scaffold):**
- `routes/web.php`
- `resources/js/Pages/Auth/Login.vue`
- `resources/js/Pages/Dashboard.vue`
- `.env`

---

### Task 1: Docker Infrastructure

**Files:**
- Create: `docker/php/Dockerfile`
- Create: `docker/nginx/default.conf`
- Create: `docker-compose.yml`

- [ ] **Step 1: Criar Dockerfile**

```dockerfile
# docker/php/Dockerfile
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev libzip-dev \
    zip unzip && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
```

- [ ] **Step 2: Criar Nginx config**

```nginx
# docker/nginx/default.conf
server {
    listen 80;
    server_name localhost;
    root /var/www/html/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 3: Criar docker-compose.yml**

```yaml
# docker-compose.yml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    volumes:
      - .:/var/www/html
    networks:
      - tabresolve
    depends_on:
      mysql:
        condition: service_healthy

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    networks:
      - tabresolve
    depends_on:
      - app

  mysql:
    image: mysql:8.0
    ports:
      - "3306:3306"
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: tabresolve
      MYSQL_USER: tabresolve
      MYSQL_PASSWORD: secret
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - tabresolve
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
    networks:
      - tabresolve

networks:
  tabresolve:
    driver: bridge

volumes:
  mysql_data:
```

- [ ] **Step 4: Build e verificar**

```bash
docker compose build app
```

Esperado: build completa sem erros. PHP 8.3-FPM com Composer 2 e Node 20.

- [ ] **Step 5: Commit**

```bash
git add docker/ docker-compose.yml
git commit -m "feat: add Docker infrastructure (PHP 8.3, Nginx, MySQL 8, Redis 7)"
```

---

### Task 2: Laravel 13 Project Scaffolding

**Files:**
- Create: todos os arquivos Laravel 13 via `composer create-project`
- Modify: `.env`

- [ ] **Step 1: Criar projeto Laravel 13 dentro do Docker**

```bash
docker compose run --rm app bash -c \
  "composer create-project laravel/laravel /tmp/app --prefer-dist && cp -rn /tmp/app/. . && rm -rf /tmp/app"
```

O flag `-n` em `cp` não sobrescreve arquivos existentes — `.gitignore` e `docs/` são preservados. Esperado: arquivos Laravel aparecem no projeto (`artisan`, `app/`, `config/`, etc.).

- [ ] **Step 2: Configurar .env**

Abrir `.env` (criado pelo comando acima) e ajustar:

```env
APP_NAME=TabResolver
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=tabresolve
DB_USERNAME=tabresolve
DB_PASSWORD=secret

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

BROADCAST_CONNECTION=reverb
```

- [ ] **Step 3: Gerar app key**

```bash
docker compose run --rm app php artisan key:generate
```

Esperado: `Application key set successfully.`

- [ ] **Step 4: Corrigir permissões de storage**

```bash
docker compose run --rm app chmod -R 775 storage bootstrap/cache
```

- [ ] **Step 5: Subir serviços e verificar**

```bash
docker compose up -d
```

Visitar http://localhost — esperado: página de boas-vindas do Laravel (pode ser 500 antes de migrar, tudo bem).

- [ ] **Step 6: Commit**

Atenção: NUNCA commitar `.env`.

```bash
git add --all && git reset HEAD .env
git commit -m "feat: scaffold Laravel 13 project"
```

---

### Task 3: Instalar Laravel Breeze (Inertia + Vue 3)

**Files:**
- Cria: `resources/js/Pages/Auth/` (Login, Register, etc.)
- Cria: `resources/js/Pages/Dashboard.vue`
- Cria: `resources/js/Layouts/AuthenticatedLayout.vue`
- Cria: `resources/js/Layouts/GuestLayout.vue`
- Modifica: `routes/web.php`, `routes/auth.php`

- [ ] **Step 1: Instalar pacote Breeze**

```bash
docker compose run --rm app composer require laravel/breeze --dev
```

- [ ] **Step 2: Scaffoldar Breeze com Inertia + Vue 3**

```bash
docker compose run --rm app php artisan breeze:install vue
```

Quando perguntado sobre dark mode: `no`
Quando perguntado sobre Pest: `yes`

Esperado: arquivos Vue de auth, layouts, componentes e rotas criados.

- [ ] **Step 3: Instalar dependências npm**

```bash
docker compose run --rm app npm install
```

- [ ] **Step 4: Rodar migrações**

```bash
docker compose run --rm app php artisan migrate
```

Esperado: tabelas `users`, `sessions`, `password_reset_tokens`, `jobs`, `cache` criadas.

- [ ] **Step 5: Build frontend**

```bash
docker compose run --rm app npm run build
```

Esperado: `public/build/` criado com assets compilados.

- [ ] **Step 6: Verificar login no browser**

Visitar http://localhost/login — esperado: formulário de login do Breeze com campos email e senha.
Testar registro em http://localhost/register — esperado: funciona e redireciona para /dashboard.

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "feat: install Laravel Breeze with Inertia + Vue 3"
```

---

### Task 4: Escrever Tests de Proteção de Rotas (TDD)

**Files:**
- Create: `tests/Feature/Auth/RouteProtectionTest.php`

- [ ] **Step 1: Criar o arquivo de test**

```php
<?php
// tests/Feature/Auth/RouteProtectionTest.php

use App\Models\User;

it('redirects unauthenticated users from /dashboard to /login', function () {
    $this->get('/dashboard')
        ->assertRedirect('/login');
});

it('redirects unauthenticated users from /sessions/create to /login', function () {
    $this->get('/sessions/create')
        ->assertRedirect('/login');
});

it('allows authenticated users to access /dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

it('allows authenticated users to access /sessions/create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/sessions/create')
        ->assertOk();
});

it('redirects root / to login when unauthenticated', function () {
    $this->get('/')
        ->assertRedirect('/login');
});

it('redirects root / to dashboard when authenticated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/dashboard');
});
```

- [ ] **Step 2: Rodar os testes para confirmar que falham**

```bash
docker compose run --rm app php artisan test tests/Feature/Auth/RouteProtectionTest.php
```

Esperado: 2–3 testes falham (rota `/sessions/create` não existe ainda, `/` não redireciona corretamente).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Auth/RouteProtectionTest.php
git commit -m "test: add route protection tests (red)"
```

---

### Task 5: Configurar Rotas + SessionController

**Files:**
- Create: `app/Http/Controllers/SessionController.php`
- Create: `resources/js/Pages/Sessions/Create.vue` (placeholder temporário)
- Modify: `routes/web.php`

- [ ] **Step 1: Criar SessionController**

```php
<?php
// app/Http/Controllers/SessionController.php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Sessions/Create');
    }
}
```

- [ ] **Step 2: Criar placeholder Sessions/Create.vue**

```bash
mkdir -p resources/js/Pages/Sessions
```

```vue
<!-- resources/js/Pages/Sessions/Create.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Nova Sessão" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Nova Sessão</h2>
        </template>
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <p class="text-gray-600">Carregando...</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 3: Substituir routes/web.php**

```php
<?php
// routes/web.php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))
        ->name('dashboard');

    Route::get('/sessions/create', [SessionController::class, 'create'])
        ->name('sessions.create');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
```

- [ ] **Step 4: Rodar os testes para confirmar que passam**

```bash
docker compose run --rm app php artisan test tests/Feature/Auth/RouteProtectionTest.php
```

Esperado: todos os 6 testes PASS.

```
PASS  Tests\Feature\Auth\RouteProtectionTest
✓ redirects unauthenticated users from /dashboard to /login
✓ redirects unauthenticated users from /sessions/create to /login
✓ allows authenticated users to access /dashboard
✓ allows authenticated users to access /sessions/create
✓ redirects root / to login when unauthenticated
✓ redirects root / to dashboard when authenticated
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SessionController.php routes/web.php resources/js/Pages/Sessions/
git commit -m "feat: protect /dashboard and /sessions/create with auth middleware"
```

---

### Task 6: Personalizar Página de Login

**Files:**
- Modify: `resources/js/Pages/Auth/Login.vue`

- [ ] **Step 1: Substituir conteúdo de Login.vue**

```vue
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
            <h1 class="text-3xl font-bold text-gray-900">TabResolver</h1>
            <p class="mt-2 text-sm text-gray-500">Divida a conta sem discussão</p>
        </div>

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
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
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="rounded border-gray-300 text-indigo-600"
                    />
                    Lembrar-me
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm text-indigo-600 hover:text-indigo-500"
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

            <p class="text-center text-sm text-gray-500">
                Não tem conta?
                <Link :href="route('register')" class="text-indigo-600 hover:text-indigo-500">
                    Registrar-se
                </Link>
            </p>
        </form>
    </GuestLayout>
</template>
```

- [ ] **Step 2: Rebuild assets**

```bash
docker compose run --rm app npm run build
```

- [ ] **Step 3: Verificar no browser**

Visitar http://localhost — esperado: redireciona para /login com título "TabResolver", subtítulo "Divida a conta sem discussão", formulário com labels em PT-BR.
Testar login com credenciais criadas no Task 3 — esperado: redireciona para /dashboard.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Auth/Login.vue
git commit -m "feat: customize login page with TabResolver branding (PT-BR)"
```

---

### Task 7: Personalizar Dashboard

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`

- [ ] **Step 1: Substituir Dashboard.vue**

```vue
<!-- resources/js/Pages/Dashboard.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Minhas Sessões" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Minhas Sessões</h2>
                <Link
                    :href="route('sessions.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
                >
                    + Nova Sessão
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-12 text-center">
                    <div class="text-5xl mb-4">🧾</div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">
                        Nenhuma sessão ainda
                    </h3>
                    <p class="text-sm text-gray-500 mb-6 max-w-xs mx-auto">
                        Crie uma sessão, envie o link para o grupo e deixe a IA dividir a conta.
                    </p>
                    <Link
                        :href="route('sessions.create')"
                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-6 py-3 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
                    >
                        Criar primeira sessão →
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Rebuild e verificar**

```bash
docker compose run --rm app npm run build
```

Visitar http://localhost/dashboard (após login) — esperado: header "Minhas Sessões" com botão "+ Nova Sessão", card de empty state com emoji 🧾 e texto em PT-BR.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Dashboard.vue
git commit -m "feat: add TabResolver dashboard page with empty state"
```

---

### Task 8: Criar Página Sessions/Create

**Files:**
- Modify: `resources/js/Pages/Sessions/Create.vue`

- [ ] **Step 1: Substituir Sessions/Create.vue com formulário completo**

Nota: o form envia para `/sessions` (POST) que não existe ainda — será implementado na Fase 2. O envio retornará 404, mas a página deve renderizar corretamente.

```vue
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
```

- [ ] **Step 2: Rebuild e verificar**

```bash
docker compose run --rm app npm run build
```

Visitar http://localhost/sessions/create (após login) — esperado: formulário com campo "Nome da sessão", área de upload com ícone 📷, aviso da IA em azul e botão "Criar Sessão →". Clicar no botão deve retornar 404 (esperado — rota POST implementada na Fase 2).

- [ ] **Step 3: Rodar suite completa de testes**

```bash
docker compose run --rm app php artisan test
```

Esperado: todos os testes passam, incluindo os testes padrão do Breeze e os 6 testes de RouteProtectionTest.

- [ ] **Step 4: Commit final da fase 1**

```bash
git add resources/js/Pages/Sessions/Create.vue
git commit -m "feat: add sessions/create page with upload form (Phase 1 complete)"
```

---

## Verificação Final

Após concluir todos os tasks, confirmar manualmente:

1. http://localhost → redireciona para /login
2. http://localhost/login → exibe formulário TabResolver com PT-BR
3. http://localhost/dashboard (sem auth) → redireciona para /login
4. http://localhost/sessions/create (sem auth) → redireciona para /login
5. Login com credenciais válidas → redireciona para /dashboard
6. /dashboard (autenticado) → exibe "Minhas Sessões" com empty state e botão "+ Nova Sessão"
7. /sessions/create (autenticado) → exibe formulário de criação com upload de imagem
8. `php artisan test` → todos os testes PASS
