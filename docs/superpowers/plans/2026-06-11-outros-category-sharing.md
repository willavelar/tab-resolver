# "Outros" Category + Per-Session Sharing Toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a third item category "outros" (non-consumables like parking) with its own per-session "shared vs. ask" toggle that mirrors the existing `food_shared` behavior, surfaced only when the bill contains "outros" items.

**Architecture:** Extend the `ItemCategory` enum, teach the AI extractor to emit `other`, add an `others_shared` boolean to `Session`, and generalize `BillReconciler`'s leftover-handling policy so an unclaimed item is silently split when `(food && food_shared) || (other && others_shared)` and otherwise prompts a clarification. The flag is threaded `AnalyzeBill → BillSplitter → BillReconciler`, toggled via a new owner-only route, and rendered as a second toggle in `Sessions/Show.vue` gated on the presence of "outros" items.

**Tech Stack:** PHP 8.3, Laravel 13, Prism (Anthropic/OpenAI), Vue 3 + Inertia, Pest. All commands run inside the `app` Docker container.

---

### Task 1: Add the `Other` case to the category enum

**Files:**
- Modify: `app/Enums/ItemCategory.php`
- Test: `tests/Unit/ItemCategoryTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ItemCategoryTest.php`:

```php
<?php

use App\Enums\ItemCategory;

it('exposes the outros category with a PT-BR label', function () {
    expect(ItemCategory::Other->value)->toBe('other')
        ->and(ItemCategory::Other->label())->toBe('Outros');
});

it('still exposes food and drink labels', function () {
    expect(ItemCategory::Food->label())->toBe('Comida')
        ->and(ItemCategory::Drink->label())->toBe('Bebida');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=ItemCategoryTest`
Expected: FAIL — "Undefined constant App\Enums\ItemCategory::Other".

- [ ] **Step 3: Add the enum case and label**

In `app/Enums/ItemCategory.php`, add the case and extend the `match`:

```php
enum ItemCategory: string
{
    case Food = 'food';
    case Drink = 'drink';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Comida',
            self::Drink => 'Bebida',
            self::Other => 'Outros',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=ItemCategoryTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Enums/ItemCategory.php tests/Unit/ItemCategoryTest.php
git commit -m "feat(category): add outros item category"
```

---

### Task 2: Add `others_shared` to the Session model

**Files:**
- Create: `database/migrations/<timestamp>_add_others_shared_to_bill_sessions_table.php`
- Modify: `app/Models/Session.php:35` (fillable), `app/Models/Session.php:54` (casts)
- Test: `tests/Feature/SessionTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/SessionTest.php`:

```php
it('defaults others_shared to false and casts it to boolean', function () {
    $session = App\Models\Session::factory()->for(App\Models\User::factory())->create();

    expect($session->fresh()->others_shared)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="defaults others_shared"`
Expected: FAIL — column `others_shared` does not exist / attribute null.

- [ ] **Step 3: Create the migration**

Run: `docker compose exec app php artisan make:migration add_others_shared_to_bill_sessions_table`

Replace the generated file's body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->boolean('others_shared')->default(false)->after('food_shared');
        });
    }

    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropColumn('others_shared');
        });
    }
};
```

- [ ] **Step 4: Add to fillable and casts**

In `app/Models/Session.php`, add `'others_shared'` to the `$fillable` array (next to `'food_shared'`) and add the cast in the `casts()` array (next to `'food_shared' => 'boolean'`):

```php
// in $fillable, after 'food_shared',
'others_shared',
```
```php
// in casts(), after 'food_shared' => 'boolean',
'others_shared' => 'boolean',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter="others_shared"`
Expected: PASS. (`RefreshDatabase` runs migrations against SQLite in-memory, so no manual migrate needed.)

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/Session.php tests/Feature/SessionTest.php
git commit -m "feat(session): add others_shared flag defaulting to false"
```

---

### Task 3: Generalize the reconciler's leftover-sharing policy

**Files:**
- Modify: `app/Services/Bill/BillReconciler.php:20-28` (signature), `:110-136` (leftover loop)
- Test: `tests/Unit/BillReconcilerTest.php` (append)

The current leftover loop only shares unclaimed *food* when `foodShared`. We add an
`bool $othersShared = false` parameter (defaulted so existing named-arg callers keep
working) and extend the "is this leftover silently shared?" decision to cover the
`other` category. The clarification sentence's category word now comes from the enum
label so "outros" reads correctly.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/BillReconcilerTest.php`:

```php
function receiptWithParking(): array
{
    return [
        ['name' => 'Parmegiana', 'quantity' => 1.0, 'unit_price' => 100.00, 'total_price' => 100.00, 'category' => 'food'],
        ['name' => 'Estacionamento', 'quantity' => 1.0, 'unit_price' => 20.00, 'total_price' => 20.00, 'category' => 'other'],
    ];
}

it('asks who consumed an unclaimed outros item when others is not shared', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [['name' => 'Parmegiana', 'quantity' => 1.0]]],
        ['participant_id' => 'c', 'items' => []],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptWithParking(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: false,
        othersShared: false,
        serviceChargePercentage: 0.0,
        total: 120.00,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeTrue()
        ->and($result->questions[0]['prompt'])->toContain('Estacionamento')
        ->and($result->questions[0]['prompt'])->toContain('outros');
});

it('splits an unclaimed outros item equally when others is shared', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [['name' => 'Parmegiana', 'quantity' => 1.0]]],
        ['participant_id' => 'c', 'items' => []],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptWithParking(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: false,
        othersShared: true,
        serviceChargePercentage: 0.0,
        total: 120.00,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeFalse();

    $byId = collect($result->allocations)->keyBy('participant_id');
    // 20.00 parking split equally between the two participants.
    expect($byId['w']['shared_food_share'])->toBe(10.00)
        ->and($byId['c']['shared_food_share'])->toBe(10.00);

    $grand = collect($result->allocations)->sum('total');
    expect(abs($grand - 120.00))->toBeLessThanOrEqual(0.01);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=BillReconcilerTest`
Expected: FAIL — `reconcile()` has no `othersShared` named argument (`Unknown named parameter`).

- [ ] **Step 3: Add the parameter to the signature**

In `app/Services/Bill/BillReconciler.php`, add `bool $othersShared` as the **trailing**
optional parameter (after `bool $forceFinal`). It must be last: a defaulted parameter
placed before the required `$serviceChargePercentage`/`$total`/`$forceFinal` params is,
in PHP 8.1+, implicitly treated as required (with a deprecation), which would break the
existing reconciler tests that omit it. Trailing-optional avoids that entirely, and
every caller uses named arguments so call sites read identically regardless of position:

```php
    public function reconcile(
        array $items,
        array $participants,
        array $claims,
        bool $foodShared,
        float $serviceChargePercentage,
        float $total,
        bool $forceFinal,
        bool $othersShared = false,
    ): SplitResult {
```

Keep the existing PHPDoc block above the method unchanged.

- [ ] **Step 4: Generalize the leftover loop**

Replace the leftover loop block (currently lines ~110-136, the `$sharedFoodValue`
loop) with this version. It renames the internal accumulator to `$sharedValue`,
extends the shared decision to the `other` category, and derives the category word
from the enum label:

```php
        $sharedValue = 0.0;
        foreach ($catalog as $entry) {
            $left = $entry['remaining'];
            if ($left <= 0.001) {
                continue;
            }

            $value = round($left * $entry['unit_price'], 2);

            if ($forceFinal) {
                $sharedValue = round($sharedValue + $value, 2);

                continue;
            }

            $category = $entry['category'];
            $isShared = ($category === ItemCategory::Food->value && $foodShared)
                || ($category === ItemCategory::Other->value && $othersShared);

            if ($isShared) {
                $sharedValue = round($sharedValue + $value, 2);

                continue;
            }

            $kind = mb_strtolower(ItemCategory::tryFrom($category)?->label() ?? $category);
            $questions[] = $this->question(
                "Sobrou {$this->qty($left)}x \"{$entry['name']}\" ({$kind}) sem dono. Quem consumiu?"
            );
        }
```

Then update the equal-split divisor line (currently `round($sharedFoodValue / count(...), 2)`)
to use the renamed variable:

```php
        $share = count($participants) > 0
            ? round($sharedValue / count($participants), 2)
            : 0.0;
```

And update the `$raw` array key source (currently `'shared_food_value' => $sharedFoodValue`)
to reference the renamed variable while KEEPING the existing key name:

```php
        $raw = [
            'shared_food_value' => $sharedValue,
            'computed_total' => $running,
        ];
```

Leave the `shared_food_share` allocation key (in the `$allocations[] = [...]` block) exactly as-is.

- [ ] **Step 5: Run the full reconciler suite to verify all pass**

Run: `docker compose exec app php artisan test --filter=BillReconcilerTest`
Expected: PASS — the two new tests plus all pre-existing reconciler tests (the old
ones omit `othersShared`, defaulting to false; none use the `other` category, so their
behavior is unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Bill/BillReconciler.php tests/Unit/BillReconcilerTest.php
git commit -m "feat(reconciler): share or query unclaimed outros items via othersShared"
```

---

### Task 4: Thread `othersShared` through the splitter chain

**Files:**
- Modify: `app/Services/Bill/BillSplitter.php:16` (interface)
- Modify: `app/Services/Bill/PrismBillSplitter.php:21` (signature), `:48-56` (reconcile call)
- Modify: `app/Services/Bill/FakeBillSplitter.php:9` (signature), `:35-43` (reconcile call)
- Modify: `app/Jobs/AnalyzeBill.php:62-68` (positional call)
- Modify: `tests/Feature/FakeBillSplitterTest.php:37` (positional call)

`AnalyzeBill` and `FakeBillSplitterTest` call `split()` **positionally**, so they MUST
be updated together with the signature change or their arguments shift. New canonical
signature inserts `bool $othersShared` after `bool $foodShared`.

- [ ] **Step 1: Update the interface**

In `app/Services/Bill/BillSplitter.php`, change the method signature to:

```php
    public function split(Session $session, array $participants, bool $foodShared, bool $othersShared = false, array $answered = [], bool $forceFinal = false): SplitResult;
```

- [ ] **Step 2: Update PrismBillSplitter**

In `app/Services/Bill/PrismBillSplitter.php`, change the signature:

```php
    public function split(Session $session, array $participants, bool $foodShared, bool $othersShared = false, array $answered = [], bool $forceFinal = false): SplitResult
```

And in the `reconcile(...)` call near the end of `split`, add the `othersShared` argument:

```php
        return (new BillReconciler)->reconcile(
            items: $items,
            participants: $participants,
            claims: $claims->raw['claims'] ?? [],
            foodShared: $foodShared,
            othersShared: $othersShared,
            serviceChargePercentage: (float) $session->service_charge_percentage,
            total: (float) $session->total,
            forceFinal: $forceFinal,
        );
```

(Optional: add `'others_shared' => $othersShared` to the opening `Log::info` context array for parity with `food_shared`.)

- [ ] **Step 3: Update FakeBillSplitter**

In `app/Services/Bill/FakeBillSplitter.php`, change the signature:

```php
    public function split(Session $session, array $participants, bool $foodShared, bool $othersShared = false, array $answered = [], bool $forceFinal = false): SplitResult
```

And add `othersShared: $othersShared,` to its `reconcile(...)` call, immediately after the `foodShared: $foodShared,` line.

- [ ] **Step 4: Update AnalyzeBill's positional call**

In `app/Jobs/AnalyzeBill.php`, update the `$splitter->split(...)` call to pass the new flag in position:

```php
        $result = $splitter->split(
            $this->session,
            $participants,
            (bool) $this->session->food_shared,
            (bool) $this->session->others_shared,
            $answered,
            $forceFinal,
        );
```

- [ ] **Step 5: Update the FakeBillSplitter feature test's positional call**

In `tests/Feature/FakeBillSplitterTest.php`, line 37, insert the `othersShared` argument:

```php
    $result = app(BillSplitter::class)->split($session, $participants, true, false, [], false);
```

- [ ] **Step 6: Run the affected suites to verify all pass**

Run: `docker compose exec app php artisan test --filter="FakeBillSplitter|AnalyzeBill"`
Expected: PASS — splitter chain consistent end-to-end.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Bill/BillSplitter.php app/Services/Bill/PrismBillSplitter.php app/Services/Bill/FakeBillSplitter.php app/Jobs/AnalyzeBill.php tests/Feature/FakeBillSplitterTest.php
git commit -m "feat(bill): thread others_shared through the splitter chain"
```

---

### Task 5: Teach the AI extractor to emit the `other` category

**Files:**
- Modify: `app/Services/Receipt/PrismReceiptExtractor.php:58` (schema enum), `:104` & `:127` (validation guards), `:156-159` (prompt)
- Test: `tests/Unit/PrismReceiptPromptTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/PrismReceiptPromptTest.php`:

```php
it('tells the model about the outros category for non-consumables', function () {
    $prompt = (new PrismReceiptExtractor)->buildPrompt();

    expect($prompt)
        ->toContain('other')
        ->toContain('estacionamento');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="outros category for non-consumables"`
Expected: FAIL — prompt does not contain "estacionamento".

- [ ] **Step 3: Update the category enum schema**

In `app/Services/Receipt/PrismReceiptExtractor.php`, change the category `EnumSchema` (line ~58) to:

```php
                            new EnumSchema('category', 'food para comida, drink para bebida, other para itens não consumíveis (ex.: estacionamento, couvert, taxas)', ['food', 'drink', 'other']),
```

- [ ] **Step 4: Update the two validation guards**

There are two identical fallback guards (lines ~104 and ~127):

```php
'category' => in_array($item['category'] ?? null, ['food', 'drink'], true) ? $item['category'] : 'food',
```

Change BOTH to include `'other'`:

```php
'category' => in_array($item['category'] ?? null, ['food', 'drink', 'other'], true) ? $item['category'] : 'food',
```

- [ ] **Step 5: Update the prompt text**

In `buildPrompt()`, change the category clause (line ~157) from
`'drink para bebida). Informe também subtotal e total. Use números (sem '` so it reads:

```php
            .'quantidade, preço unitário, preço total e a categoria (food para comida, '
            .'drink para bebida, other para itens não consumíveis como estacionamento, '
            .'couvert ou taxas). Informe também subtotal e total. Use números (sem '
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=PrismReceiptPromptTest`
Expected: PASS (all prompt tests, including the new one).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Receipt/PrismReceiptExtractor.php tests/Unit/PrismReceiptPromptTest.php
git commit -m "feat(extraction): let the AI categorize non-consumables as outros"
```

---

### Task 6: Owner-only route to toggle `others_shared`

**Files:**
- Create: `app/Http/Requests/UpdateOthersSharedRequest.php`
- Modify: `app/Http/Controllers/SessionController.php` (add `updateOthersShared`, add `use` import, add `others_shared` to `show()` props at line ~398)
- Modify: `routes/web.php:46-47` (add route)
- Test: `tests/Feature/AnalyzeSessionTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AnalyzeSessionTest.php`:

```php
it('lets the owner toggle others_shared', function () {
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['others_shared' => false]);

    $this->actingAs($user)
        ->patch(route('sessions.others-shared', $session), ['others_shared' => true])
        ->assertRedirect();

    expect($session->fresh()->others_shared)->toBeTrue();
});

it('forbids a non-owner from toggling others_shared', function () {
    $session = Session::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('sessions.others-shared', $session), ['others_shared' => true])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter="others_shared"`
Expected: FAIL — route `sessions.others-shared` is not defined.

- [ ] **Step 3: Create the form request**

Create `app/Http/Requests/UpdateOthersSharedRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOthersSharedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'others_shared' => ['required', 'boolean'],
        ];
    }
}
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/SessionController.php`, add the import near the other request
imports (next to `use App\Http\Requests\UpdateFoodSharedRequest;`):

```php
use App\Http\Requests\UpdateOthersSharedRequest;
```

Add this method immediately after the existing `updateFoodShared` method:

```php
    public function updateOthersShared(UpdateOthersSharedRequest $request, Session $session): RedirectResponse
    {
        if ($session->user_id !== auth()->id()) {
            abort(403);
        }

        if ($session->analysis_status === AnalysisStatus::Processing) {
            abort(403);
        }

        $session->update(['others_shared' => $request->validated('others_shared')]);

        return back();
    }
```

- [ ] **Step 5: Expose the flag to the view**

In the `show()` method's Inertia props array, add immediately after the
`'food_shared' => $session->food_shared,` line:

```php
                'others_shared' => $session->others_shared,
```

- [ ] **Step 6: Register the route**

In `routes/web.php`, immediately after the `sessions.food-shared` route (lines ~46-47), add:

```php
    Route::patch('/sessions/{session}/others-shared', [SessionController::class, 'updateOthersShared'])
        ->name('sessions.others-shared');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter="others_shared"`
Expected: PASS — both new feature tests plus the Session cast test from Task 2.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/UpdateOthersSharedRequest.php app/Http/Controllers/SessionController.php routes/web.php tests/Feature/AnalyzeSessionTest.php
git commit -m "feat(session): owner-only route to toggle others_shared"
```

---

### Task 7: Render the "outros" toggle in Show.vue

**Files:**
- Modify: `resources/js/Pages/Sessions/Show.vue` (add `hasOtherItems` computed, `setOthersShared` method, toggle markup, help text)

This is presentational and mirrors the existing food toggle. No automated test (the
project has no Vue component test harness); verify by manual/visual check.

- [ ] **Step 1: Add the computed flag and setter**

In the `<script setup>` block, add a computed near the other `computed(...)` definitions
(e.g. just after `analysisGrandTotal`):

```js
const hasOtherItems = computed(() =>
    (props.session.items ?? []).some((i) => i.category === 'other'),
);
```

And add a setter immediately after the existing `setFoodShared` function:

```js
const setOthersShared = (value) => {
    if (value === props.session.others_shared) {
        return;
    }

    router.patch(
        route('sessions.others-shared', props.session.id),
        { others_shared: value },
        { preserveScroll: true },
    );
};
```

- [ ] **Step 2: Add the toggle markup**

In the "Bill analysis" section, immediately AFTER the closing `</div>` of the existing
food toggle group (the `<div class="mt-3 flex w-full ...">` block that ends just before
the `<p class="mt-2 text-xs text-muted">` help text, around line 517), insert:

```html
                                <div
                                    v-if="hasOtherItems"
                                    class="mt-3 flex w-full rounded-md border border-hairline bg-surface-strong p-1"
                                >
                                    <button
                                        type="button"
                                        class="flex-1 rounded px-3 py-2.5 text-sm font-medium transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="
                                            session.others_shared
                                                ? 'bg-surface-card text-ink shadow-sm'
                                                : 'text-muted hover:text-body'
                                        "
                                        :disabled="session.analysis_status === 'processing'"
                                        @click="setOthersShared(true)"
                                    >
                                        Outros compartilhados
                                    </button>
                                    <button
                                        type="button"
                                        class="flex-1 rounded px-3 py-2.5 text-sm font-medium transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="
                                            !session.others_shared
                                                ? 'bg-surface-card text-ink shadow-sm'
                                                : 'text-muted hover:text-body'
                                        "
                                        :disabled="session.analysis_status === 'processing'"
                                        @click="setOthersShared(false)"
                                    >
                                        Outros não compartilhados
                                    </button>
                                </div>
```

- [ ] **Step 3: Extend the help text**

Update the help-text paragraph (around line 519) to mention "outros":

```html
                                <p class="mt-2 text-xs text-muted">
                                    Comida não reivindicada é dividida igualmente; bebidas são sempre individuais. Itens em "outros" (ex.: estacionamento) seguem a mesma regra quando marcados como compartilhados.
                                </p>
```

- [ ] **Step 4: Build assets to verify the component compiles**

Run: `docker compose exec app npm run build`
Expected: build completes with no Vue compile errors.

- [ ] **Step 5: Manual verification**

With the stack up (`docker compose up -d`), open a session whose receipt has an item
categorized `other`. Confirm: (a) the "Outros compartilhados / Outros não compartilhados"
toggle appears below the food toggle; (b) it is absent for a session with no "outros"
items; (c) clicking persists the choice (page reflects it after reload).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Sessions/Show.vue
git commit -m "feat(ui): show the outros sharing toggle when the bill has outros items"
```

---

### Final verification

- [ ] **Run the full suite**

Run: `docker compose exec app composer run test`
Expected: all tests green.

- [ ] **Run Pint**

Run: `docker compose exec app ./vendor/bin/pint`
Expected: no style violations (or auto-fixed; commit any changes).

---

## Notes for the implementer

- **Why `shared_food_share` keeps its name:** the field is persisted inside each
  participant's `breakdown` JSON and read by the Vue analysis view. Renaming it would
  break already-stored data and the frontend. Only the internal accumulator variable is
  renamed (`sharedFoodValue → sharedValue`) for clarity. (See spec §4 / "Out of scope".)
- **Default `others_shared = false`** is deliberate: unclaimed "outros" items prompt
  "who consumed this?" unless the owner opts into sharing.
- **Parameter placement / named vs. positional calls:** `reconcile()` takes
  `othersShared` as a *trailing* optional param to avoid PHP 8.1's "optional before
  required" deprecation (which would make it implicitly required and break callers that
  omit it). All `reconcile()` callers use named args, so position is cosmetic there.
  `split()`, by contrast, inserts `othersShared` after `foodShared` — safe because every
  later `split()` param already had a default — but it has *positional* callers
  (`AnalyzeBill`, `FakeBillSplitterTest`), which is why Task 4 updates them together.
