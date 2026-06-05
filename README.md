# TabResolver

Bill-splitting web app. Users create a session for a restaurant or bar tab, upload a photo of the receipt, and AI reads the items automatically. Participants are assigned to the items they consumed and the app calculates who owes what.

## Stack

| Layer | Technology |
|---|---|
| **Backend** | PHP 8.3 · Laravel 13 |
| **Frontend** | Vue 3 · Inertia.js v2 |
| **Styling** | Tailwind CSS v3 |
| **Build** | Vite 8 (`laravel-vite-plugin`) |
| **Auth** | Laravel Breeze (session-based · Sanctum) |
| **Database** | MySQL 8 (Docker) · SQLite in-memory (tests) |
| **Cache / Sessions / Queues** | Redis 7 |
| **Testing** | Pest v4 · PHPUnit |
| **Infra** | Docker Compose · Nginx |

## Directory layout

```
app/
  Http/
    Controllers/        Thin controllers — logic in services/actions
    Controllers/Auth/   Breeze auth controllers
    Middleware/         HandleInertiaRequests (shared props)
    Requests/           Form Request validation classes
  Models/               Eloquent models (ULID primary keys)
  Providers/            AppServiceProvider
resources/
  js/
    Components/         Reusable Vue components (Breeze primitives + custom)
    Layouts/            AuthenticatedLayout, GuestLayout
    Pages/              Inertia pages — one file per route
      Auth/             Login, Register, Password flows
      Sessions/         Bill-splitting session pages
  css/app.css           Tailwind entry point
routes/
  web.php               Application routes
  auth.php              Breeze auth routes
database/
  migrations/           Standard Laravel migrations
  factories/            Model factories (Faker)
  seeders/              DatabaseSeeder
docker/
  php/Dockerfile        PHP-FPM image
  nginx/default.conf    Nginx site config
```

## Features

- **Receipt upload** — photo of a tab is uploaded and processed by AI to extract line items
- **Session management** — each bill-splitting event is an isolated session
- **Item assignment** — participants mark what they consumed
- **Split calculation** — the app totals each person's share automatically
- **Auth** — session-based authentication via Laravel Breeze

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) + Docker Compose
- [Composer](https://getcomposer.org/) + [Node.js](https://nodejs.org/) (for running outside Docker)

## Running with Docker

```bash
# Clone the repository
git clone git@github.com:willavelar/tab-resolver.git
cd tab-resolver

# Create the environment file
cp .env.example .env
# Edit .env and set APP_KEY (or let artisan generate it)

# Start all services
docker compose up --build

# In another terminal, run setup
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Access: **http://localhost**

## Running locally

```bash
# Install PHP dependencies
composer install

# Install JS dependencies
npm install

# Copy and edit the .env file
cp .env.example .env
# Update DB_HOST, DB_USERNAME, DB_PASSWORD and REDIS_HOST to match your local services

# Generate app key and run migrations
php artisan key:generate
php artisan migrate --seed

# Start everything in one command (Laravel + queue worker + Pail + Vite)
composer run dev
```

## Environment variables

Create a `.env` file at the root based on `.env.example`:

```env
APP_NAME=TabResolver
APP_ENV=local
APP_KEY=           # generate with: php artisan key:generate
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
```

## Useful commands

```bash
# Start the full stack (server + queue + logs + Vite)
composer run dev

# Run migrations
php artisan migrate

# Fresh migration with seed
php artisan migrate:fresh --seed

# Run tests (SQLite in-memory — no Docker required)
composer run test

# Run a specific test
php artisan test --filter=ExampleTest

# Code style (Pint)
./vendor/bin/pint

# Build frontend assets
npm run build
```

## Architecture

### Inertia + Vue pattern

There is no separate API. The backend renders Inertia responses and Vue handles the UI. Data flows one way:

1. Laravel controller calls `Inertia::render('PageName', $props)`.
2. `HandleInertiaRequests` middleware merges shared props (auth user, flash messages) into every response.
3. Vue page component receives props via `defineProps`.
4. Mutations go through `useForm` from `@inertiajs/vue3` — POST/PATCH/DELETE back to Laravel routes.

### Frontend routes

| Route | Access | Description |
|---|---|---|
| `/` | Public | Redirects to dashboard or login |
| `/login` | Guest | Login |
| `/register` | Guest | Sign up |
| `/dashboard` | Auth | Main dashboard |
| `/sessions/create` | Auth | Create a new bill-splitting session |
| `/profile` | Auth | Edit profile |

### Primary keys

All models use **ULID** primary keys (`$table->ulid('id')->primary()`). Never use auto-increment integers for new tables.

### Queue

`QUEUE_CONNECTION=redis` in production/Docker. In tests `QUEUE_CONNECTION=sync` (see `phpunit.xml`) so jobs run inline without a worker.

### Testing conventions

- **Pest** is the test runner — use `it()`, `test()`, `expect()` syntax.
- Tests run against **SQLite in-memory** — fast, no external services needed.
- Use `RefreshDatabase` trait to reset state between tests.
- Feature tests hit real routes via `$this->get()` / `$this->post()` etc.

## License

MIT
