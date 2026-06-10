# Itens da conta + Resumo na página pública Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a session's AI extraction is `completed`, the public link
(`/c/{token}`) shows "Itens da conta" (grouped Comida/Bebida + totals) and
"Resumo" instead of the receipt photo; any other status keeps showing the
photo as today.

**Architecture:** `PublicSessionController::show` gains the same
items/totals/summary props that `SessionController::show` already exposes
(reusing `ReceiptSummary::for()`). `Public/Session.vue` gets the same
`brl()`/`foodItems`/`drinkItems` helpers and item/totals/summary markup as
`Sessions/Show.vue` (minus the "copy summary" button), shown via `v-if` on
`session.status === 'completed'`, with the existing `<img>` as the `v-else`.

**Tech Stack:** Laravel 13 (Pest tests), Inertia.js v2 + Vue 3, Tailwind CSS.

---

### Task 1: Backend — failing tests for items/totals/summary on the public page

**Files:**
- Test: `tests/Feature/PublicParticipantTest.php`

- [ ] **Step 1: Add the new imports**

In `tests/Feature/PublicParticipantTest.php`, the current imports are:

```php
use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
```

Replace with:

```php
use App\Enums\ExtractionStatus;
use App\Enums\ItemCategory;
use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
```

- [ ] **Step 2: Write the two failing tests**

Add these two tests at the end of `tests/Feature/PublicParticipantTest.php`
(after the last `test(...)` block in the file):

```php
test('the public page exposes items, totals and summary when extraction is completed', function () {
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'subtotal' => 50,
        'service_charge' => 5,
        'service_charge_percentage' => 10,
        'total' => 55,
    ]);
    SessionItem::create([
        'bill_session_id' => $session->id,
        'name' => 'Heineken',
        'quantity' => 1,
        'unit_price' => 9.90,
        'total_price' => 9.90,
        'category' => ItemCategory::Drink,
        'position' => 1,
    ]);

    $this->get("/c/{$session->public_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('session.status', 'completed')
            ->where('session.items.0.name', 'Heineken')
            ->where('session.items.0.category', 'drink')
            ->where('session.total', fn ($v) => (float) $v === 55.0)
            ->where('session.summary_markdown', fn ($v) => str_contains((string) $v, '## Bebida'))
        );
});

test('the public page has no items or summary when extraction is not completed', function () {
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Pending,
    ]);

    $this->get("/c/{$session->public_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('session.status', 'pending')
            ->where('session.items', [])
            ->where('session.summary_markdown', null)
            ->has('session.image_url')
        );
});
```

- [ ] **Step 3: Run the new tests and confirm they fail**

Run: `docker compose exec app php artisan test --filter=PublicParticipantTest`

Expected: the two new tests **FAIL** (e.g. `session.status` / `session.items`
/ `session.total` / `session.summary_markdown` keys are missing from the
Inertia props), all pre-existing tests in the file still **PASS**.

---

### Task 2: Backend — expose items/totals/summary in `PublicSessionController::show`

**Files:**
- Modify: `app/Http/Controllers/PublicSessionController.php`

- [ ] **Step 1: Add the imports**

Current top-of-file imports:

```php
use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
```

Replace with:

```php
use App\Enums\ExtractionStatus;
use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Services\Receipt\ReceiptSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
```

- [ ] **Step 2: Load items and extend the `session` prop**

Current `show()` method:

```php
    public function show(Request $request, string $token): Response
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        $existing = $this->existingParticipant($request, $session);

        return Inertia::render('Public/Session', [
            'session' => [
                'title' => $session->title,
                'image_url' => Storage::disk('public')->url($session->image_path),
                'token' => $session->public_token,
            ],
            'alreadySubmitted' => $existing !== null,
            'submittedName' => $existing?->name,
        ]);
    }
```

Replace with:

```php
    public function show(Request $request, string $token): Response
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        $session->load('items');

        $existing = $this->existingParticipant($request, $session);

        return Inertia::render('Public/Session', [
            'session' => [
                'title' => $session->title,
                'image_url' => Storage::disk('public')->url($session->image_path),
                'token' => $session->public_token,
                'status' => $session->status->value,
                'subtotal' => $session->subtotal,
                'service_charge' => $session->service_charge,
                'service_charge_percentage' => $session->service_charge_percentage,
                'total' => $session->total,
                'items' => $session->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'category' => $item->category?->value,
                ]),
                'summary_markdown' => $session->status === ExtractionStatus::Completed
                    ? ReceiptSummary::for($session)
                    : null,
            ],
            'alreadySubmitted' => $existing !== null,
            'submittedName' => $existing?->name,
        ]);
    }
```

- [ ] **Step 3: Run the tests and confirm they pass**

Run: `docker compose exec app php artisan test --filter=PublicParticipantTest`

Expected: all tests in `PublicParticipantTest.php` **PASS**, including the two
new ones from Task 1.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/PublicSessionController.php tests/Feature/PublicParticipantTest.php
git commit -m "feat(public): expose extracted items, totals and summary on the public page"
```

---

### Task 3: Frontend — currency formatter and item-group computeds

**Files:**
- Modify: `resources/js/Pages/Public/Session.vue`

- [ ] **Step 1: Add `brl`, `foodItems` and `drinkItems`**

In `resources/js/Pages/Public/Session.vue`, the `<script setup>` currently
ends with the `submit` function (right before `</script>`):

```js
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
```

Add the new helpers right after that function, before `</script>`:

```js
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

const brl = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
        Number(value ?? 0),
    );

const foodItems = computed(() =>
    (props.session.items ?? []).filter((i) => i.category === 'food'),
);
const drinkItems = computed(() =>
    (props.session.items ?? []).filter((i) => i.category === 'drink'),
);
</script>
```

`computed` is already imported from `'vue'` at the top of the file (`import {
computed, ref } from 'vue';`), so no import changes are needed.

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Public/Session.vue
git commit -m "feat(public): add currency formatter and item-group computeds"
```

---

### Task 4: Frontend — show items/totals/summary instead of the photo when completed

**Files:**
- Modify: `resources/js/Pages/Public/Session.vue`

- [ ] **Step 1: Replace the image block with the conditional items/summary block**

Current `<template>` block (right after the intro paragraph):

```html
            <div class="mt-4 overflow-hidden rounded-lg border border-hairline">
                <img
                    :src="session.image_url"
                    :alt="`Foto da conta — ${session.title}`"
                    class="block w-full object-contain"
                />
            </div>
```

Replace with:

```html
            <div v-if="session.status === 'completed'" class="mt-4">
                <h3 class="text-sm font-semibold text-ink">Itens da conta</h3>

                <div
                    v-for="group in [
                        { title: 'Comida', items: foodItems },
                        { title: 'Bebida', items: drinkItems },
                    ]"
                    :key="group.title"
                >
                    <template v-if="group.items.length">
                        <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-muted">
                            {{ group.title }}
                        </h4>
                        <div class="mt-2 overflow-hidden rounded-md border border-hairline">
                            <table class="w-full text-sm">
                                <thead class="bg-surface-strong text-muted">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium">Item</th>
                                        <th class="px-3 py-2 text-right font-medium">Qtd</th>
                                        <th class="px-3 py-2 text-right font-medium">Unit.</th>
                                        <th class="px-3 py-2 text-right font-medium">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="item in group.items"
                                        :key="item.id"
                                        class="border-t border-hairline"
                                    >
                                        <td class="px-3 py-2 text-ink">{{ item.name }}</td>
                                        <td class="px-3 py-2 text-right text-body">{{ Number(item.quantity) }}</td>
                                        <td class="px-3 py-2 text-right text-body">{{ brl(item.unit_price) }}</td>
                                        <td class="px-3 py-2 text-right text-body">{{ brl(item.total_price) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>

                <div class="mt-4 overflow-hidden rounded-md border border-hairline-strong">
                    <table class="w-full text-sm">
                        <tbody>
                            <tr>
                                <td class="px-3 py-2 text-right text-muted">Sub-total</td>
                                <td class="px-3 py-2 text-right text-body w-32">{{ brl(session.subtotal) }}</td>
                            </tr>
                            <tr v-if="Number(session.service_charge) > 0">
                                <td class="px-3 py-2 text-right text-muted">
                                    Gorjeta<span v-if="session.service_charge_percentage"> ({{ Number(session.service_charge_percentage) }}%)</span>
                                </td>
                                <td class="px-3 py-2 text-right text-body">{{ brl(session.service_charge) }}</td>
                            </tr>
                            <tr class="border-t border-hairline">
                                <td class="px-3 py-2 text-right font-semibold text-ink">Total</td>
                                <td class="px-3 py-2 text-right font-semibold text-ink">{{ brl(session.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="session.summary_markdown" class="mt-6">
                    <h3 class="text-sm font-semibold text-ink">Resumo</h3>
                    <pre class="mt-2 whitespace-pre-wrap rounded-md border border-hairline bg-surface-strong p-4 text-sm text-body">{{ session.summary_markdown }}</pre>
                </div>
            </div>

            <div v-else class="mt-4 overflow-hidden rounded-lg border border-hairline">
                <img
                    :src="session.image_url"
                    :alt="`Foto da conta — ${session.title}`"
                    class="block w-full object-contain"
                />
            </div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Public/Session.vue
git commit -m "feat(public): show extracted items and summary instead of the receipt photo when ready"
```

---

### Task 5: Verify in the browser

**Files:** none (manual verification)

- [ ] **Step 1: Ensure the stack is up**

Run: `docker compose up -d`

- [ ] **Step 2: Find a completed session's public token and a pending one**

Run:
```bash
docker compose exec app php artisan tinker --execute="
\App\Models\Session::where('status', 'completed')->first(['id','public_token','title']);
\App\Models\Session::where('status', 'pending')->first(['id','public_token','title']);
"
```

If no `completed` session exists, run the AI extraction on an existing
session first (`POST /sessions/{id}/extract` from the authenticated UI, with
`ANTHROPIC_API_KEY` set and `horizon` running), then re-run the query above.

- [ ] **Step 3: Open both public links in the browser**

- `http://localhost:8080/c/{completed_token}` → expect "Itens da conta"
  (grouped Comida/Bebida tables), Sub-total/Gorjeta/Total, and "Resumo" —
  **no receipt photo**.
- `http://localhost:8080/c/{pending_token}` → expect the receipt photo, as
  before — **no items/summary**.
- In both cases, the participant form (nome + áudio/texto) still renders
  below and a test submission still works.

---

### Task 6: Final cleanup — Pint and full test suite

**Files:** none (verification only, no expected changes if Tasks 1-2 followed
PSR-12 conventions)

- [ ] **Step 1: Run Pint**

Run: `docker compose exec app ./vendor/bin/pint`

Expected: `PASS` (or auto-fixes applied — if files changed, review the diff,
then `git add` the affected files and create a new commit, e.g.
`style: pint fixup`).

- [ ] **Step 2: Run the full test suite**

Run: `docker compose exec app composer run test`

Expected: all tests **PASS**, including the two new tests from Task 1.
