# AGENTS.md

Guidance for AI Coding Agents when working with code in this repository.

## Workflow Orchestration

### 1. Plan Mode Default

- Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately — don't keep pushing
- Use plan mode for verification steps, not just building
- Write detailed specs upfront to reduce ambiguity

### 2. Subagent Strategy

- Use subagents liberally to keep main context window clean
- Offload research, exploration, and parallel analysis to subagents
- For complex problems, throw more compute at it via subagents
- One task per subagent for focused execution

### 3. Verification Before Done

- Never mark a task complete without proving it works
- Run the full test suite before considering work done
- Verify your changes against the existing behavior
- Ask yourself: "Would a staff engineer approve this?"

### 4. Demand Elegance (Balanced)

- For nontrivial changes: pause and ask "is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution"
- Skip this for simple, obvious fixes — don't over-engineer
- Challenge your own work before presenting it

### 5. Autonomous Bug Fixing

- When given a bug report: just fix it. Don't ask for hand-holding
- Run tests to identify the root cause
- Zero context switching required from the user
- Go fix failing tests without being told how

## Core Principles

- **Simplicity First**: Make every change as simple as possible. Minimal code impact.
- **No Laziness**: Find root causes. No temporary workarounds. Senior developer standards.
- **Minimal Impact**: Changes should only touch what's necessary. Avoid introducing bugs.

---

## Repository

**TabResolver** — a bill-splitting app. Users create a "session" for a restaurant/bar tab, upload a photo of the receipt, and AI reads the items automatically. Participants are then assigned items and the app calculates who owes what.

## Stack

- **Backend**: PHP 8.3, Laravel 13 (single-app, not a monorepo)
- **Frontend**: Vue 3 + Inertia.js v2 (SPA feel without a separate API)
- **Styling**: Tailwind CSS v3
- **Build**: Vite 8 (`laravel-vite-plugin`)
- **Auth**: Laravel Breeze (session-based, Sanctum)
- **Database**: MySQL 8 (production/Docker), SQLite in-memory (tests)
- **Cache / Sessions / Queues**: Redis 7
- **Testing**: Pest v4 + PHPUnit (SQLite in-memory — no external services needed; still run in the container)
- **Language**: PT-BR throughout the UI

## Commands

> **Always run commands inside Docker — never on the host.**
> The host toolchain is unreliable (e.g. host Node is too old for Vite 8, which
> requires Node ≥ 20.19). The `app` container ships PHP 8.3, Composer, and Node 20,
> so all `php`/`artisan`/`composer`/`npm` commands must run there. Bring the stack
> up first (`docker compose up -d`), then exec into `app`.

```bash
# Start the full stack (app + nginx + mysql + redis) — app on http://localhost:8080
docker compose up -d --build

# Run any command inside the app container
docker compose exec app <command>

# Assets (Vite 8 — MUST run in the container)
docker compose exec app npm install
docker compose exec app npm run build       # production build
docker compose exec app npm run dev         # Vite HMR (expose port 5173 if used from host)

# Dev runner (Laravel + queue worker + Pail logs + Vite, concurrently)
docker compose exec app composer run dev

# Individual services
docker compose exec app php artisan serve

# Database
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:fresh --seed

# Tests (SQLite in-memory — fast, no external services, but still run in-container)
docker compose exec app composer run test
docker compose exec app php artisan test --filter=ExampleTest

# Code style (Pint)
docker compose exec app ./vendor/bin/pint
```

## Architecture

### Directory layout

```
app/
  Http/
    Controllers/        Standard Laravel controllers (thin — logic in services/actions)
    Controllers/Auth/   Breeze auth controllers (avoid touching unless necessary)
    Middleware/         HandleInertiaRequests shares props to every page
    Requests/           Form Request validation classes
  Models/               Eloquent models
  Providers/            AppServiceProvider (boot/register)
resources/
  js/
    Components/         Reusable Vue components (Breeze primitives + custom)
    Layouts/            AuthenticatedLayout, GuestLayout
    Pages/              Inertia pages — one file per route
      Auth/             Login, Register, Password flows
      Profile/          Edit profile + partials
      Sessions/         Bill-splitting session pages
  css/app.css           Tailwind entry point
routes/
  web.php               Main routes (auth-gated group + root redirect)
  auth.php              Breeze auth routes (login, register, password reset, verify)
database/
  migrations/           Standard Laravel migrations
  factories/            Model factories (Faker)
  seeders/              DatabaseSeeder
tests/
  Feature/              HTTP/feature tests via Pest
  Unit/                 Pure unit tests (sparse for now)
docker/
  php/Dockerfile        PHP-FPM image
  nginx/default.conf    Nginx site config
```

### Inertia + Vue pattern

There is no separate API. The backend renders Inertia responses and Vue handles the UI. Data flows one way:

1. Laravel controller calls `Inertia::render('PageName', $props)`.
2. `HandleInertiaRequests` middleware merges shared props (auth user, flash messages) into every response.
3. Vue page component receives props via `defineProps`.
4. Mutations go through `useForm` from `@inertiajs/vue3` — POST/PATCH/DELETE back to Laravel routes.

### Primary keys

All models use **ULID** primary keys (`$table->ulid('id')->primary()`). Never use auto-increment integers for new tables.

### Authentication

Breeze provides session-based auth. The `auth` middleware group gates all application routes. The root `/` redirects to `dashboard` if authenticated, otherwise to `login`.

### Queue

`QUEUE_CONNECTION=redis` in production/Docker. In tests `QUEUE_CONNECTION=sync` (see `phpunit.xml`) so jobs run inline without a worker.

### Receipt AI extraction (Reverb + queue)

The session show page reads receipts via AI (Prism + Anthropic Claude vision),
triggered by a button. For it to work in dev, these must be running and set:

- `ANTHROPIC_API_KEY` (+ optional `ANTHROPIC_RECEIPT_MODEL`) in `.env`
- Queue worker: `docker compose exec app php artisan queue:work redis --tries=3`
  (or the `queue` compose service)
- Reverb websocket: `docker compose exec app php artisan reverb:start`
  (or the `reverb` compose service), with `REVERB_*` / `VITE_REVERB_*` env set

Flow: `POST /sessions/{id}/extract` → `ExtractReceiptItems` job → Prism/Anthropic
→ persists `session_items` + totals + `raw_extraction` → broadcasts
`ReceiptExtractionUpdated` on private channel `bill-session.{id}` → Vue updates live.

### Testing conventions

- **Pest** is the test runner — write tests using Pest syntax (`it()`, `test()`, `expect()`), not PHPUnit class style.
- Tests run against **SQLite in-memory** — fast, no external services needed.
- Use `RefreshDatabase` trait to reset state between tests.
- Feature tests hit real routes via `$this->get()`/`$this->post()` etc.
- No mocking the database — integration tests should hit real queries.

### Frontend conventions

- Pages live in `resources/js/Pages/` and are named with PascalCase matching the `Inertia::render()` first argument.
- Reusable components live in `resources/js/Components/`.
- Use Breeze's existing primitives (`PrimaryButton`, `TextInput`, `InputLabel`, `InputError`) before creating new components.
- Ziggy (`tightenco/ziggy`) is available — use `route('name')` inside Vue templates.
- UI copy is in **PT-BR**.

## Code Conventions

- **PHP**: PSR-12 style enforced by Laravel Pint. Run `./vendor/bin/pint` before committing.
- **TypeScript**: not configured — plain JS with JSDoc if types are needed.
- **Migrations**: always generate via `php artisan make:migration`. Never hand-edit existing migration files after they've been run.
- **Form Requests**: validation belongs in `App\Http\Requests`, not inline in controllers.
- **Git authorship**: never add `Co-Authored-By: Claude` (or any AI model) to commit messages.
