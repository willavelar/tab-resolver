# Agentic Receipt Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the "Ler conta com IA" flow so the model classifies each item as Comida/Bebida, records the tip percentage, pauses to ask the user when unsure (bounded agent loop), and renders a deterministic markdown summary.

**Architecture:** The Prism structured-output schema gains a `status` discriminator (`complete` | `needs_input`). The extraction job branches on it: `needs_input` parks the session in a new `NeedsClarification` state storing pending questions; the user answers via a new `clarify` route which re-dispatches the job with the answers appended. A round cap (2) plus a `forceFinal` flag guarantees termination. The summary is built server-side from stored data.

**Tech Stack:** PHP 8.3, Laravel 13, Prism (Anthropic vision), Pest, Vue 3 + Inertia, Tailwind. **All commands run inside Docker:** prefix with `docker compose exec app`.

---

## Conventions for every task

- Run tests with: `docker compose exec app php artisan test --filter=<name>`
- Run Pint before each commit: `docker compose exec app ./vendor/bin/pint`
- Migrations are generated, never hand-edited after running. This plan shows the
  final body to paste into the generated file.
- Never add `Co-Authored-By` trailers to commits (project rule).

---

## File Structure

**Create:**
- `app/Enums/ItemCategory.php` — Comida/Bebida enum with PT-BR label.
- `app/Http/Requests/ClarifyExtractionRequest.php` — validates clarification answers.
- `app/Services/Receipt/ReceiptSummary.php` — deterministic markdown builder.
- 3 migrations (category, service_charge_percentage, clarifications).

**Modify:**
- `app/Enums/ExtractionStatus.php` — add `NeedsClarification`.
- `app/Models/SessionItem.php` — cast `category`, add to `$fillable`.
- `app/Models/Session.php` — cast + fillable for new columns.
- `app/Services/Receipt/ExtractionResult.php` — discriminated result.
- `app/Services/Receipt/ReceiptExtractor.php` — new method signature.
- `app/Services/Receipt/FakeReceiptExtractor.php` — new contract + categories.
- `app/Services/Receipt/PrismReceiptExtractor.php` — discriminated schema + prompt.
- `app/Jobs/ExtractReceiptItems.php` — branch on discriminator + round cap.
- `app/Http/Controllers/SessionController.php` — `clarify()`, reset on extract, new props.
- `routes/web.php` — `sessions.clarify` route.
- `resources/js/Pages/Sessions/Show.vue` — clarification form, grouped items, summary.
- `tests/Feature/ReceiptExtractionTest.php` — update signatures + new assertions.
- `database/factories/SessionItemFactory.php` — default category.

---

## Task 1: `ItemCategory` enum

**Files:**
- Create: `app/Enums/ItemCategory.php`
- Test: `tests/Feature/ReceiptExtractionTest.php`

- [ ] **Step 1: Write the failing test** (append to `tests/Feature/ReceiptExtractionTest.php`)

```php
test('item category enum exposes pt-br labels', function () {
    expect(\App\Enums\ItemCategory::Food->value)->toBe('food')
        ->and(\App\Enums\ItemCategory::Drink->value)->toBe('drink')
        ->and(\App\Enums\ItemCategory::Food->label())->toBe('Comida')
        ->and(\App\Enums\ItemCategory::Drink->label())->toBe('Bebida');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="item category enum"`
Expected: FAIL — `Class "App\Enums\ItemCategory" not found`.

- [ ] **Step 3: Create the enum**

```php
<?php

namespace App\Enums;

enum ItemCategory: string
{
    case Food = 'food';
    case Drink = 'drink';

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Comida',
            self::Drink => 'Bebida',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter="item category enum"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add app/Enums/ItemCategory.php tests/Feature/ReceiptExtractionTest.php
git commit -m "feat(extraction): add ItemCategory enum"
```

---

## Task 2: `ExtractionStatus::NeedsClarification`

**Files:**
- Modify: `app/Enums/ExtractionStatus.php`
- Test: `tests/Feature/ReceiptExtractionTest.php`

- [ ] **Step 1: Write the failing test** (append)

```php
test('extraction status has a needs_clarification case', function () {
    expect(\App\Enums\ExtractionStatus::NeedsClarification->value)->toBe('needs_clarification');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="needs_clarification case"`
Expected: FAIL — undefined constant `NeedsClarification`.

- [ ] **Step 3: Add the case** to `app/Enums/ExtractionStatus.php`, after `Completed`:

```php
    case Completed = 'completed';
    case NeedsClarification = 'needs_clarification';
    case Failed = 'failed';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter="needs_clarification case"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/ExtractionStatus.php tests/Feature/ReceiptExtractionTest.php
git commit -m "feat(extraction): add NeedsClarification status"
```

---

## Task 3: Migrations + model casts for new columns

**Files:**
- Create: 3 migration files (generated)
- Modify: `app/Models/SessionItem.php`, `app/Models/Session.php`, `database/factories/SessionItemFactory.php`
- Test: `tests/Feature/ReceiptExtractionTest.php`

- [ ] **Step 1: Write the failing test** (append)

```php
test('session item casts category and session casts new extraction fields', function () {
    $session = \App\Models\Session::factory()->for(\App\Models\User::factory())->create([
        'service_charge_percentage' => 10,
        'clarifications' => ['round' => 1, 'answered' => [], 'pending' => []],
    ]);

    $item = \App\Models\SessionItem::create([
        'bill_session_id' => $session->id,
        'name' => 'Heineken',
        'quantity' => 2,
        'unit_price' => 9.90,
        'total_price' => 19.80,
        'category' => \App\Enums\ItemCategory::Drink,
        'position' => 1,
    ]);

    $session->refresh();

    expect($item->fresh()->category)->toBe(\App\Enums\ItemCategory::Drink)
        ->and((float) $session->service_charge_percentage)->toBe(10.0)
        ->and($session->clarifications)->toBeArray()
        ->and($session->clarifications['round'])->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="casts category"`
Expected: FAIL — unknown column `category` / `service_charge_percentage`.

- [ ] **Step 3a: Generate the migrations**

```bash
docker compose exec app php artisan make:migration add_category_to_session_items_table
docker compose exec app php artisan make:migration add_tip_percentage_and_clarifications_to_bill_sessions_table
```

- [ ] **Step 3b: Fill `add_category_to_session_items_table`** `up()`/`down()`:

```php
    public function up(): void
    {
        Schema::table('session_items', function (Blueprint $table) {
            $table->string('category')->nullable()->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('session_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
```

- [ ] **Step 3c: Fill `add_tip_percentage_and_clarifications_to_bill_sessions_table`:**

```php
    public function up(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->decimal('service_charge_percentage', 5, 2)->nullable()->after('service_charge');
            $table->json('clarifications')->nullable()->after('raw_extraction');
        });
    }

    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropColumn(['service_charge_percentage', 'clarifications']);
        });
    }
```

- [ ] **Step 3d: Update `SessionItem`** — add `category` to `$fillable` and cast:

```php
    protected $fillable = [
        'bill_session_id',
        'name',
        'quantity',
        'unit_price',
        'total_price',
        'category',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'category' => \App\Enums\ItemCategory::class,
            'position' => 'integer',
        ];
    }
```

- [ ] **Step 3e: Update `Session`** — add to `$fillable` and `casts()`:

```php
    protected $fillable = [
        'title',
        'image_path',
        'public_token',
        'status',
        'subtotal',
        'service_charge',
        'service_charge_percentage',
        'total',
        'raw_extraction',
        'clarifications',
        'processed_at',
        'failure_reason',
    ];
```

In `casts()` add these two entries alongside the existing ones:

```php
            'service_charge_percentage' => 'decimal:2',
            'clarifications' => 'array',
```

- [ ] **Step 3f: Update `SessionItemFactory`** — add a default category to `definition()`'s returned array:

```php
            'category' => fake()->randomElement([\App\Enums\ItemCategory::Food, \App\Enums\ItemCategory::Drink]),
```

- [ ] **Step 4: Run migrations + test**

Run: `docker compose exec app php artisan test --filter="casts category"`
Expected: PASS (the test harness migrates the in-memory SQLite automatically via `RefreshDatabase`).

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add database/migrations app/Models/SessionItem.php app/Models/Session.php database/factories/SessionItemFactory.php tests/Feature/ReceiptExtractionTest.php
git commit -m "feat(extraction): add category, tip percentage, clarifications columns"
```

---

## Task 4: Discriminated `ExtractionResult`

**Files:**
- Modify: `app/Services/Receipt/ExtractionResult.php`
- Test: `tests/Unit/ExtractionResultTest.php` (create)

- [ ] **Step 1: Write the failing test** — create `tests/Unit/ExtractionResultTest.php`:

```php
<?php

use App\Services\Receipt\ExtractionResult;

test('complete result carries items and totals and is not needs-input', function () {
    $result = ExtractionResult::complete(
        items: [['name' => 'X', 'quantity' => 1.0, 'unit_price' => 5.0, 'total_price' => 5.0, 'category' => 'food']],
        subtotal: 5.0,
        serviceCharge: 0.5,
        serviceChargePercentage: 10.0,
        total: 5.5,
        raw: ['status' => 'complete'],
    );

    expect($result->status)->toBe('complete')
        ->and($result->needsInput())->toBeFalse()
        ->and($result->items)->toHaveCount(1)
        ->and($result->serviceChargePercentage)->toBe(10.0);
});

test('requestInput result carries questions and is needs-input', function () {
    $result = ExtractionResult::requestInput(
        questions: [['id' => 'q1', 'prompt' => 'Comida ou Bebida?', 'type' => 'choice', 'options' => ['Comida', 'Bebida']]],
        raw: ['status' => 'needs_input'],
    );

    expect($result->status)->toBe('needs_input')
        ->and($result->needsInput())->toBeTrue()
        ->and($result->questions)->toHaveCount(1)
        ->and($result->items)->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=ExtractionResult`
Expected: FAIL — `complete()` / `requestInput()` undefined.

- [ ] **Step 3: Replace `ExtractionResult` contents**

```php
<?php

namespace App\Services\Receipt;

class ExtractionResult
{
    /**
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: string}>  $items
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly array $items = [],
        public readonly float $subtotal = 0.0,
        public readonly float $serviceCharge = 0.0,
        public readonly ?float $serviceChargePercentage = null,
        public readonly float $total = 0.0,
        public readonly array $questions = [],
        public readonly array $raw = [],
    ) {}

    public function needsInput(): bool
    {
        return $this->status === 'needs_input';
    }

    /**
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: string}>  $items
     * @param  array<string, mixed>  $raw
     */
    public static function complete(
        array $items,
        float $subtotal,
        float $serviceCharge,
        ?float $serviceChargePercentage,
        float $total,
        array $raw,
    ): self {
        return new self(
            status: 'complete',
            items: $items,
            subtotal: $subtotal,
            serviceCharge: $serviceCharge,
            serviceChargePercentage: $serviceChargePercentage,
            total: $total,
            raw: $raw,
        );
    }

    /**
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    public static function requestInput(array $questions, array $raw): self
    {
        return new self(status: 'needs_input', questions: $questions, raw: $raw);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=ExtractionResult`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add app/Services/Receipt/ExtractionResult.php tests/Unit/ExtractionResultTest.php
git commit -m "feat(extraction): make ExtractionResult a discriminated result"
```

---

## Task 5: Extractor contract + `FakeReceiptExtractor`

**Files:**
- Modify: `app/Services/Receipt/ReceiptExtractor.php`, `app/Services/Receipt/FakeReceiptExtractor.php`
- Test: `tests/Feature/ReceiptExtractionTest.php` (update existing "fake extractor" test)

- [ ] **Step 1: Update the existing fake-extractor test** — replace the body of the test named `the fake extractor returns a deterministic result` with:

```php
test('the fake extractor returns a deterministic result', function () {
    $extractor = new FakeReceiptExtractor;

    expect($extractor)->toBeInstanceOf(ReceiptExtractor::class);

    $result = $extractor->extract('/tmp/whatever.jpg');

    expect($result->status)->toBe('complete')
        ->and($result->items)->toHaveCount(2)
        ->and($result->items[0]['name'])->toBe('Cerveja Heineken')
        ->and($result->items[0]['category'])->toBe('drink')
        ->and($result->items[1]['category'])->toBe('food')
        ->and($result->subtotal)->toBe(50.0)
        ->and($result->serviceCharge)->toBe(5.0)
        ->and($result->serviceChargePercentage)->toBe(10.0)
        ->and($result->total)->toBe(55.0)
        ->and($result->raw)->toBeArray();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="fake extractor returns a deterministic"`
Expected: FAIL — `status` property / `category` key missing.

- [ ] **Step 3a: Update the interface** `app/Services/Receipt/ReceiptExtractor.php`:

```php
<?php

namespace App\Services\Receipt;

interface ReceiptExtractor
{
    /**
     * Read a receipt image and return either a completed extraction or a set of
     * clarification questions when the model is unsure.
     *
     * @param  string  $absoluteImagePath  Absolute path to the receipt image on local disk.
     * @param  array<int, array{question: string, answer: string}>  $answered  Prior Q&A to feed back into the model.
     * @param  bool  $forceFinal  When true, the model must return a complete result (no more questions).
     */
    public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult;
}
```

- [ ] **Step 3b: Update `FakeReceiptExtractor`:**

```php
<?php

namespace App\Services\Receipt;

class FakeReceiptExtractor implements ReceiptExtractor
{
    public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
    {
        $items = [
            ['name' => 'Cerveja Heineken', 'quantity' => 2.0, 'unit_price' => 15.0, 'total_price' => 30.0, 'category' => 'drink'],
            ['name' => 'Porção de batata', 'quantity' => 1.0, 'unit_price' => 20.0, 'total_price' => 20.0, 'category' => 'food'],
        ];

        return ExtractionResult::complete(
            items: $items,
            subtotal: 50.0,
            serviceCharge: 5.0,
            serviceChargePercentage: 10.0,
            total: 55.0,
            raw: ['status' => 'complete', 'items' => $items, 'subtotal' => 50.0, 'service_charge' => 5.0, 'total' => 55.0],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter="fake extractor returns a deterministic"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add app/Services/Receipt/ReceiptExtractor.php app/Services/Receipt/FakeReceiptExtractor.php tests/Feature/ReceiptExtractionTest.php
git commit -m "feat(extraction): branch extractor contract + fake categories"
```

---

## Task 6: Job branches on the discriminator + round cap

**Files:**
- Modify: `app/Jobs/ExtractReceiptItems.php`
- Test: `tests/Feature/ReceiptExtractionTest.php`

This task also fixes the existing throwing-extractor test, whose anonymous class
must match the new interface signature.

- [ ] **Step 1a: Fix the existing throwing test** — in the test named `the job marks the session failed when extraction throws`, update the anonymous class method signature:

```php
        public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
        {
            throw new RuntimeException('boom');
        }
```

- [ ] **Step 1b: Update the existing "persists items and totals" test** to also assert categories + percentage. Add these assertions inside its `expect(...)` chain (before the closing `;`):

```php
        ->and((float) $session->service_charge_percentage)->toBe(10.0)
        ->and($session->items()->where('category', 'drink')->count())->toBe(1)
        ->and($session->items()->where('category', 'food')->count())->toBe(1)
```

- [ ] **Step 1c: Write the new failing test** (append) — needs_input parks the session:

```php
test('the job parks the session for clarification when the model asks', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new class implements ReceiptExtractor
    {
        public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
        {
            return ExtractionResult::requestInput(
                questions: [['id' => 'q1', 'prompt' => 'Caipirinha é Comida ou Bebida?', 'type' => 'choice', 'options' => ['Comida', 'Bebida']]],
                raw: ['status' => 'needs_input'],
            );
        }
    });

    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Processing,
        'image_path' => 'receipts/example.jpg',
    ]);

    ExtractReceiptItems::dispatchSync($session);
    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::NeedsClarification)
        ->and($session->items()->count())->toBe(0)
        ->and($session->clarifications['pending'])->toHaveCount(1)
        ->and($session->clarifications['pending'][0]['id'])->toBe('q1');

    Event::assertDispatched(ReceiptExtractionUpdated::class, function ($e) use ($session) {
        return $e->sessionId === $session->id && $e->status === ExtractionStatus::NeedsClarification->value;
    });
});

test('the job forces a final result once the round cap is reached', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new class implements ReceiptExtractor
    {
        public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
        {
            // Would keep asking, but on the final round the job ignores questions.
            return ExtractionResult::requestInput(questions: [['id' => 'q1', 'prompt' => 'x', 'type' => 'text', 'options' => []]], raw: []);
        }
    });

    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Processing,
        'image_path' => 'receipts/example.jpg',
        'clarifications' => ['round' => 2, 'answered' => [], 'pending' => []],
    ]);

    ExtractReceiptItems::dispatchSync($session);
    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::Completed);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter="parks the session for clarification"`
Expected: FAIL — job does not branch yet.

- [ ] **Step 3: Replace `handle()` in `ExtractReceiptItems.php`** and add the constant + helper. Final file:

```php
<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Events\ReceiptExtractionUpdated;
use App\Models\Session;
use App\Services\Receipt\ExtractionResult;
use App\Services\Receipt\ReceiptExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractReceiptItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Hard cap on clarification rounds before forcing a final result. */
    public const MAX_ROUNDS = 2;

    public int $tries = 3;

    /**
     * Vision API calls can take a while; keep this above the worker default (60s)
     * so a slow extraction is not SIGKILL'd (which would leave the session stuck
     * in "processing" because failed() never runs on a kill).
     */
    public int $timeout = 120;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function __construct(public Session $session) {}

    public function handle(ReceiptExtractor $extractor): void
    {
        $clarifications = $this->session->clarifications ?? [];
        $answered = $clarifications['answered'] ?? [];
        $round = $clarifications['round'] ?? 0;
        $forceFinal = $round >= self::MAX_ROUNDS;

        $absolutePath = Storage::disk('public')->path($this->session->image_path);

        $result = $extractor->extract($absolutePath, $answered, $forceFinal);

        if ($result->needsInput() && ! $forceFinal) {
            $this->session->forceFill([
                'status' => ExtractionStatus::NeedsClarification,
                'clarifications' => [
                    'round' => $round,
                    'answered' => $answered,
                    'pending' => $result->questions,
                ],
                'raw_extraction' => $result->raw,
                'failure_reason' => null,
            ])->save();

            event(new ReceiptExtractionUpdated(
                $this->session->id,
                ExtractionStatus::NeedsClarification->value,
            ));

            return;
        }

        $this->session->items()->delete();

        foreach ($result->items as $index => $item) {
            $this->session->items()->create([
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'],
                'category' => $item['category'],
                'position' => $index + 1,
            ]);
        }

        $this->session->forceFill([
            'status' => ExtractionStatus::Completed,
            'subtotal' => $result->subtotal,
            'service_charge' => $result->serviceCharge,
            'service_charge_percentage' => $result->serviceChargePercentage ?? $this->derivePercentage($result),
            'total' => $result->total,
            'raw_extraction' => $result->raw,
            'clarifications' => null,
            'processed_at' => now(),
            'failure_reason' => null,
        ])->save();

        event(new ReceiptExtractionUpdated(
            $this->session->id,
            ExtractionStatus::Completed->value,
        ));
    }

    public function failed(Throwable $exception): void
    {
        $this->session->forceFill([
            'status' => ExtractionStatus::Failed,
            'failure_reason' => $exception->getMessage(),
        ])->save();

        event(new ReceiptExtractionUpdated(
            $this->session->id,
            ExtractionStatus::Failed->value,
            $exception->getMessage(),
        ));
    }

    /**
     * Derive the tip percentage when only the absolute charge is known.
     */
    private function derivePercentage(ExtractionResult $result): ?float
    {
        if ($result->subtotal > 0 && $result->serviceCharge > 0) {
            return round($result->serviceCharge / $result->subtotal * 100, 2);
        }

        return null;
    }
}
```

- [ ] **Step 4: Run the extraction suite**

Run: `docker compose exec app php artisan test --filter=ReceiptExtractionTest`
Expected: PASS (all, including the updated existing tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add app/Jobs/ExtractReceiptItems.php tests/Feature/ReceiptExtractionTest.php
git commit -m "feat(extraction): branch job on clarification + round cap"
```

---

## Task 7: `clarify` route, request, and controller action

**Files:**
- Create: `app/Http/Requests/ClarifyExtractionRequest.php`
- Modify: `routes/web.php`, `app/Http/Controllers/SessionController.php`
- Test: `tests/Feature/ReceiptExtractionTest.php`

- [ ] **Step 1: Write the failing tests** (append)

```php
test('the owner can answer clarification questions and re-dispatch', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create([
        'status' => ExtractionStatus::NeedsClarification,
        'clarifications' => [
            'round' => 0,
            'answered' => [],
            'pending' => [['id' => 'q1', 'prompt' => 'Caipirinha é Comida ou Bebida?', 'type' => 'choice', 'options' => ['Comida', 'Bebida']]],
        ],
    ]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/clarify", ['answers' => ['q1' => 'Bebida']])
        ->assertRedirect("/sessions/{$session->id}");

    $session->refresh();

    expect($session->status)->toBe(ExtractionStatus::Processing)
        ->and($session->clarifications['round'])->toBe(1)
        ->and($session->clarifications['answered'])->toHaveCount(1)
        ->and($session->clarifications['answered'][0]['question'])->toBe('Caipirinha é Comida ou Bebida?')
        ->and($session->clarifications['answered'][0]['answer'])->toBe('Bebida');

    Queue::assertPushed(ExtractReceiptItems::class);
});

test('clarify is rejected unless the session is awaiting clarification', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $session = Session::factory()->for($owner)->create(['status' => ExtractionStatus::Completed]);

    $this->actingAs($owner)
        ->post("/sessions/{$session->id}/clarify", ['answers' => ['q1' => 'Bebida']])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a non-owner cannot answer clarification questions', function () {
    Queue::fake();
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::NeedsClarification,
        'clarifications' => ['round' => 0, 'answered' => [], 'pending' => [['id' => 'q1', 'prompt' => 'x', 'type' => 'text', 'options' => []]]],
    ]);

    $this->actingAs(User::factory()->create())
        ->post("/sessions/{$session->id}/clarify", ['answers' => ['q1' => 'y']])
        ->assertForbidden();

    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter="answer clarification"`
Expected: FAIL — route `sessions.clarify` not defined (404/MethodNotAllowed).

- [ ] **Step 3a: Create `ClarifyExtractionRequest`:**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ClarifyExtractionRequest extends FormRequest
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
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['required', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 3b: Add the route** in `routes/web.php`, right after the `extract` route:

```php
    Route::post('/sessions/{session}/clarify', [SessionController::class, 'clarify'])
        ->name('sessions.clarify');
```

- [ ] **Step 3c: Update `extract()` and add `clarify()`** in `SessionController.php`. Update the `extract()` guard + reset, then add the new method.

Update the `abort_if` list and the `update()` call inside `extract()`:

```php
        abort_if(
            in_array($session->status, [
                ExtractionStatus::Processing,
                ExtractionStatus::Completed,
                ExtractionStatus::NeedsClarification,
            ], true),
            403,
        );

        $session->update([
            'status' => ExtractionStatus::Processing,
            'failure_reason' => null,
            'clarifications' => null,
        ]);
```

Add the import at the top: `use App\Http\Requests\ClarifyExtractionRequest;`

Add the method after `extract()`:

```php
    public function clarify(ClarifyExtractionRequest $request, Session $session): RedirectResponse
    {
        abort_unless($session->user_id === auth()->id(), 403);
        abort_unless($session->status === ExtractionStatus::NeedsClarification, 403);

        $clarifications = $session->clarifications ?? [];
        $answered = $clarifications['answered'] ?? [];
        $answers = $request->validated('answers');

        foreach ($clarifications['pending'] ?? [] as $question) {
            if (! array_key_exists($question['id'], $answers)) {
                continue;
            }

            $answered[] = [
                'question' => $question['prompt'],
                'answer' => $answers[$question['id']],
            ];
        }

        $session->update([
            'status' => ExtractionStatus::Processing,
            'clarifications' => [
                'round' => ($clarifications['round'] ?? 0) + 1,
                'answered' => $answered,
                'pending' => [],
            ],
        ]);

        event(new ReceiptExtractionUpdated($session->id, ExtractionStatus::Processing->value));

        ExtractReceiptItems::dispatch($session);

        return redirect()->route('sessions.show', $session);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter="clarification"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add app/Http/Requests/ClarifyExtractionRequest.php routes/web.php app/Http/Controllers/SessionController.php tests/Feature/ReceiptExtractionTest.php
git commit -m "feat(extraction): add clarify endpoint to answer agent questions"
```

---

## Task 8: `ReceiptSummary` markdown builder

**Files:**
- Create: `app/Services/Receipt/ReceiptSummary.php`
- Test: `tests/Feature/ReceiptSummaryTest.php` (create)

- [ ] **Step 1: Write the failing test** — create `tests/Feature/ReceiptSummaryTest.php`:

```php
<?php

use App\Enums\ExtractionStatus;
use App\Enums\ItemCategory;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\User;
use App\Services\Receipt\ReceiptSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it builds a grouped markdown summary matching the template', function () {
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'subtotal' => 363.40,
        'service_charge' => 36.34,
        'service_charge_percentage' => 10,
        'total' => 399.74,
    ]);

    SessionItem::create(['bill_session_id' => $session->id, 'name' => 'Parmegiana', 'quantity' => 1, 'unit_price' => 119.90, 'total_price' => 119.90, 'category' => ItemCategory::Food, 'position' => 1]);
    SessionItem::create(['bill_session_id' => $session->id, 'name' => 'Heineken', 'quantity' => 3, 'unit_price' => 9.90, 'total_price' => 29.70, 'category' => ItemCategory::Drink, 'position' => 2]);

    $md = ReceiptSummary::for($session->fresh());

    expect($md)->toContain('# Consumidos')
        ->and($md)->toContain('## Comida')
        ->and($md)->toContain('- 1 x Parmegiana (R$ 119,90) - R$ 119,90')
        ->and($md)->toContain('## Bebida')
        ->and($md)->toContain('- 3 x Heineken (R$ 9,90) - R$ 29,70')
        ->and($md)->toContain('- Sub-total: R$ 363,40')
        ->and($md)->toContain('- Gorjeta (10%): R$ 36,34')
        ->and($md)->toContain('- Total: R$ 399,74');
});

test('it omits an empty category section and the tip line when there is no charge', function () {
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'subtotal' => 20.0,
        'service_charge' => 0,
        'service_charge_percentage' => null,
        'total' => 20.0,
    ]);

    SessionItem::create(['bill_session_id' => $session->id, 'name' => 'Água', 'quantity' => 1, 'unit_price' => 20.0, 'total_price' => 20.0, 'category' => ItemCategory::Drink, 'position' => 1]);

    $md = ReceiptSummary::for($session->fresh());

    expect($md)->not->toContain('## Comida')
        ->and($md)->toContain('## Bebida')
        ->and($md)->not->toContain('Gorjeta');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=ReceiptSummary`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `ReceiptSummary`:**

```php
<?php

namespace App\Services\Receipt;

use App\Enums\ItemCategory;
use App\Models\Session;

class ReceiptSummary
{
    public static function for(Session $session): string
    {
        $session->loadMissing('items');

        $lines = ['# Consumidos', ''];

        foreach ([ItemCategory::Food, ItemCategory::Drink] as $category) {
            $items = $session->items->filter(fn ($item) => $item->category === $category);

            if ($items->isEmpty()) {
                continue;
            }

            $lines[] = '## '.$category->label();

            foreach ($items as $item) {
                $lines[] = sprintf(
                    '- %s x %s (%s) - %s',
                    self::quantity($item->quantity),
                    $item->name,
                    self::brl($item->unit_price),
                    self::brl($item->total_price),
                );
            }

            $lines[] = '';
        }

        $lines[] = '# Valores totais';
        $lines[] = '- Sub-total: '.self::brl($session->subtotal);

        if ((float) $session->service_charge > 0) {
            $lines[] = '- Gorjeta'.self::percentageSuffix($session->service_charge_percentage).': '.self::brl($session->service_charge);
        }

        $lines[] = '- Total: '.self::brl($session->total);

        return implode("\n", $lines);
    }

    private static function quantity(int|float|string $value): string
    {
        $value = (float) $value;

        return fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : rtrim(number_format($value, 2, ',', ''), '0');
    }

    private static function percentageSuffix(int|float|string|null $percentage): string
    {
        if ($percentage === null) {
            return '';
        }

        $value = (float) $percentage;
        $formatted = fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : number_format($value, 1, ',', '');

        return ' ('.$formatted.'%)';
    }

    private static function brl(int|float|string|null $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=ReceiptSummary`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add app/Services/Receipt/ReceiptSummary.php tests/Feature/ReceiptSummaryTest.php
git commit -m "feat(extraction): add deterministic receipt summary builder"
```

---

## Task 9: Controller `show()` passes the new props

**Files:**
- Modify: `app/Http/Controllers/SessionController.php`
- Test: `tests/Feature/SessionTest.php` (append) — verify props via Inertia assertions

- [ ] **Step 1: Inspect the existing assertion style** in `tests/Feature/SessionTest.php` (it uses `assertInertia`). Then **write the failing test** (append):

```php
test('the show page exposes category, tip percentage, clarifications and summary', function () {
    $owner = \App\Models\User::factory()->create();
    $session = \App\Models\Session::factory()->for($owner)->create([
        'status' => \App\Enums\ExtractionStatus::Completed,
        'subtotal' => 50,
        'service_charge' => 5,
        'service_charge_percentage' => 10,
        'total' => 55,
    ]);
    \App\Models\SessionItem::create(['bill_session_id' => $session->id, 'name' => 'Heineken', 'quantity' => 1, 'unit_price' => 9.90, 'total_price' => 9.90, 'category' => \App\Enums\ItemCategory::Drink, 'position' => 1]);

    $this->actingAs($owner)
        ->get("/sessions/{$session->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Sessions/Show')
            ->where('session.service_charge_percentage', fn ($v) => (float) $v === 10.0)
            ->where('session.items.0.category', 'drink')
            ->where('session.summary_markdown', fn ($v) => str_contains((string) $v, '## Bebida'))
        );
});
```

> If `SessionTest.php` does not already `use` the Inertia testing helper, follow the pattern already present in that file (the existing show tests demonstrate the exact import/usage). Match it rather than inventing one.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="exposes category, tip percentage"`
Expected: FAIL — props missing.

- [ ] **Step 3: Update `show()`** in `SessionController.php`. Add the import `use App\Services\Receipt\ReceiptSummary;`. Then:

In the item map, add `category`:

```php
                'items' => $session->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'category' => $item->category?->value,
                ]),
```

Add these keys to the `'session' => [...]` array (next to `total`):

```php
                'service_charge_percentage' => $session->service_charge_percentage,
                'clarifications' => $session->clarifications,
                'summary_markdown' => $session->status === ExtractionStatus::Completed
                    ? ReceiptSummary::for($session)
                    : null,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter="exposes category, tip percentage"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add app/Http/Controllers/SessionController.php tests/Feature/SessionTest.php
git commit -m "feat(extraction): expose category, tip %, clarifications, summary to UI"
```

---

## Task 10: Show.vue — clarification form

**Files:**
- Modify: `resources/js/Pages/Sessions/Show.vue`

No automated test (no JS runner configured). Verify manually per Step 4.

- [ ] **Step 1: Add the clarify form state** in `<script setup>`, after the `extracting` ref. Import `useForm`:

Change the import line:

```js
import { Head, Link, router, useForm } from '@inertiajs/vue3';
```

Add below `triggerExtraction`:

```js
const clarifyForm = useForm({ answers: {} });

const submitClarifications = () => {
    clarifyForm.post(route('sessions.clarify', props.session.id), {
        preserveScroll: true,
        onSuccess: () => clarifyForm.reset(),
    });
};
```

- [ ] **Step 2: Add the needs_clarification template block** in the status area, between the `processing` block and the `failed` block:

```html
                        <!-- needs clarification -->
                        <div
                            v-else-if="session.status === 'needs_clarification'"
                            class="rounded-md border border-hairline bg-surface-strong p-4"
                        >
                            <p class="text-sm font-medium text-ink">A IA tem algumas dúvidas</p>
                            <p class="mt-1 text-xs text-muted">
                                Responda para concluir a leitura da conta.
                            </p>

                            <form class="mt-4 space-y-4" @submit.prevent="submitClarifications">
                                <div
                                    v-for="question in (session.clarifications?.pending ?? [])"
                                    :key="question.id"
                                >
                                    <p class="text-sm text-body">{{ question.prompt }}</p>

                                    <div v-if="question.type === 'choice'" class="mt-2 flex flex-wrap gap-2">
                                        <button
                                            v-for="option in question.options"
                                            :key="option"
                                            type="button"
                                            class="rounded-md border px-3 py-1.5 text-sm transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary"
                                            :class="clarifyForm.answers[question.id] === option
                                                ? 'border-primary bg-primary text-on-primary'
                                                : 'border-hairline-strong bg-surface-card text-ink hover:bg-canvas-soft'"
                                            @click="clarifyForm.answers[question.id] = option"
                                        >
                                            {{ option }}
                                        </button>
                                    </div>

                                    <input
                                        v-else
                                        v-model="clarifyForm.answers[question.id]"
                                        type="text"
                                        class="mt-2 block w-full rounded-md border border-hairline bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                                        placeholder="Sua resposta"
                                    />
                                </div>

                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-md border border-transparent bg-primary px-[18px] py-2.5 text-sm font-medium text-on-primary transition-colors duration-150 hover:bg-primary-active focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-50"
                                    :disabled="clarifyForm.processing"
                                >
                                    Enviar respostas
                                </button>
                            </form>
                        </div>
```

- [ ] **Step 3: Build assets**

Run: `docker compose exec app npm run build`
Expected: builds without errors. (In dev, the `vite` service hot-reloads automatically.)

- [ ] **Step 4: Manual verification**

Temporarily make the local extractor return `needs_input` (or seed a session with
`status = needs_clarification` and a `clarifications.pending` array), open
`/sessions/{id}`, confirm the questions render, pick an answer, submit, and confirm
the session transitions to `processing` then re-runs. Revert any temporary change.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Sessions/Show.vue
git commit -m "feat(extraction): render clarification questions in the session page"
```

---

## Task 11: Show.vue — grouped items + summary

**Files:**
- Modify: `resources/js/Pages/Sessions/Show.vue`

- [ ] **Step 1: Add computed groupings + summary copy** in `<script setup>`, after `brl`:

```js
import { computed } from 'vue';

const foodItems = computed(() =>
    (props.session.items ?? []).filter((i) => i.category === 'food'),
);
const drinkItems = computed(() =>
    (props.session.items ?? []).filter((i) => i.category === 'drink'),
);

const summaryCopied = ref(false);
const copySummary = async () => {
    try {
        await navigator.clipboard.writeText(props.session.summary_markdown ?? '');
        summaryCopied.value = true;
        setTimeout(() => (summaryCopied.value = false), 2000);
    } catch {
        summaryCopied.value = false;
    }
};
```

> Note: `computed` and `ref` come from `vue`; the file already imports `ref`,
> `onMounted`, `onBeforeUnmount`. Merge `computed` into that existing import line
> instead of adding a second `import ... from 'vue'`.

- [ ] **Step 2: Replace the `completed` block's item table** with grouped tables + tip percentage + summary. Replace the entire `<div v-else-if="session.status === 'completed'">...</div>` with:

```html
                        <!-- completed -->
                        <div v-else-if="session.status === 'completed'">
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
                                <div class="flex items-center justify-between">
                                    <h3 class="text-sm font-semibold text-ink">Resumo</h3>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-hairline-strong bg-surface-card px-3 py-1.5 text-xs font-medium text-ink transition-colors duration-150 hover:bg-canvas-soft focus:outline-none focus:ring-2 focus:ring-primary"
                                        @click="copySummary"
                                    >
                                        {{ summaryCopied ? '✓ Copiado!' : '📋 Copiar resumo' }}
                                    </button>
                                </div>
                                <pre class="mt-2 whitespace-pre-wrap rounded-md border border-hairline bg-surface-strong p-4 text-sm text-body">{{ session.summary_markdown }}</pre>
                            </div>
                        </div>
```

- [ ] **Step 3: Build assets**

Run: `docker compose exec app npm run build`
Expected: builds without errors.

- [ ] **Step 4: Manual verification**

Open a completed session (the `FakeReceiptExtractor` produces one drink + one food
when used locally). Confirm items appear under **Bebida**/**Comida**, the tip line
shows `Gorjeta (10%)`, the **Resumo** block renders the markdown, and "Copiar
resumo" copies it.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Sessions/Show.vue
git commit -m "feat(extraction): group items by category and show summary in UI"
```

---

## Task 12: Prism schema + prompt for the discriminated agent

**Files:**
- Modify: `app/Services/Receipt/PrismReceiptExtractor.php`

This is the real model integration. It has no isolated unit test (the existing
`ExtractorIntegrationTest` only covers `resolveCredentials()`, which is unchanged).
Correctness is verified by the manual end-to-end check in Step 4.

- [ ] **Step 1: Replace `extract()`** in `PrismReceiptExtractor.php`. Keep `resolveCredentials()` unchanged. New imports at top:

```php
use Illuminate\Support\Str;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
```

(Keep the existing `ArraySchema`, `NumberSchema`, `ObjectSchema`, `StringSchema`,
`Image`, `UserMessage`, `Provider`, `Prism` imports.)

New `extract()` body:

```php
    public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
    {
        $schema = new ObjectSchema(
            name: 'receipt',
            description: 'Resultado da leitura da conta: itens finais OU perguntas quando houver dúvida',
            properties: [
                new EnumSchema('status', 'Use "needs_input" se tiver QUALQUER dúvida; senão "complete"', ['complete', 'needs_input']),
                new ArraySchema(
                    name: 'questions',
                    description: 'Perguntas ao usuário quando status = needs_input (senão lista vazia)',
                    items: new ObjectSchema(
                        name: 'question',
                        description: 'Uma pergunta de esclarecimento',
                        properties: [
                            new StringSchema('id', 'Identificador curto e único da pergunta (ex.: q1)'),
                            new StringSchema('prompt', 'A pergunta em português'),
                            new EnumSchema('type', 'choice para escolha entre opções, text para resposta livre', ['choice', 'text']),
                            new ArraySchema('options', 'Opções quando type = choice (senão vazio)', new StringSchema('option', 'Uma opção')),
                        ],
                        requiredFields: ['id', 'prompt', 'type'],
                    ),
                ),
                new ArraySchema(
                    name: 'items',
                    description: 'Itens consumidos quando status = complete (senão lista vazia)',
                    items: new ObjectSchema(
                        name: 'item',
                        description: 'Um item da conta',
                        properties: [
                            new StringSchema('name', 'Nome do item'),
                            new NumberSchema('quantity', 'Quantidade do item'),
                            new NumberSchema('unit_price', 'Preço unitário do item'),
                            new NumberSchema('total_price', 'Preço total da linha (quantidade x unitário)'),
                            new EnumSchema('category', 'food para comida, drink para bebida', ['food', 'drink']),
                        ],
                        requiredFields: ['name', 'quantity', 'unit_price', 'total_price', 'category'],
                    ),
                ),
                new NumberSchema('subtotal', 'Subtotal dos itens, sem taxa'),
                new NumberSchema('service_charge', 'Taxa de serviço / gorjeta (valor absoluto)'),
                new NumberSchema('service_charge_percentage', 'Percentual da gorjeta quando indicado (ex.: 10). Use 0 se não houver.'),
                new NumberSchema('total', 'Total geral da conta'),
            ],
            requiredFields: ['status', 'items', 'questions', 'subtotal', 'service_charge', 'total'],
        );

        $prompt = 'Leia esta conta de restaurante/bar. Para cada item informe nome, '
            .'quantidade, preço unitário, preço total e a categoria (food para comida, '
            .'drink para bebida). Informe também subtotal, taxa de serviço (valor e '
            .'percentual quando indicado) e total. Use números (sem símbolo de moeda). '
            .'Se a taxa de serviço não existir, use 0. NÃO ADIVINHE: se tiver qualquer '
            .'dúvida sobre a categoria de um item ou não conseguir ler um valor, retorne '
            .'status "needs_input" com perguntas objetivas em "questions" (uma por dúvida), '
            .'e deixe "items" vazio. Caso contrário, retorne status "complete".';

        if ($answered !== []) {
            $prompt .= "\n\nO usuário já respondeu às seguintes dúvidas — use estas respostas:\n";
            foreach ($answered as $qa) {
                $prompt .= '- '.$qa['question'].' => '.$qa['answer']."\n";
            }
        }

        if ($forceFinal) {
            $prompt .= "\n\nEsta é a rodada final: NÃO faça mais perguntas. Use seu melhor "
                .'julgamento e retorne status "complete" com todos os itens classificados.';
        }

        $message = new UserMessage($prompt, [Image::fromLocalPath(path: $absoluteImagePath)]);

        $model = $this->resolveCredentials()['model'];

        $response = Prism::structured()
            ->using(Provider::Anthropic, $model)
            ->withSchema($schema)
            ->withProviderOptions(['use_tool_calling' => true])
            ->withMessages([$message])
            ->asStructured();

        /** @var array<string, mixed> $data */
        $data = $response->structured;

        if (($data['status'] ?? 'complete') === 'needs_input' && ! $forceFinal) {
            $questions = array_map(fn (array $q): array => [
                'id' => (string) ($q['id'] ?? Str::uuid()),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'type' => in_array($q['type'] ?? 'text', ['choice', 'text'], true) ? $q['type'] : 'text',
                'options' => array_values(array_map('strval', $q['options'] ?? [])),
            ], $data['questions'] ?? []);

            return ExtractionResult::requestInput($questions, $data);
        }

        $items = array_map(fn (array $item): array => [
            'name' => (string) $item['name'],
            'quantity' => (float) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'total_price' => (float) $item['total_price'],
            'category' => in_array($item['category'] ?? null, ['food', 'drink'], true) ? $item['category'] : 'food',
        ], $data['items'] ?? []);

        $percentage = (float) ($data['service_charge_percentage'] ?? 0);

        return ExtractionResult::complete(
            items: $items,
            subtotal: (float) ($data['subtotal'] ?? 0),
            serviceCharge: (float) ($data['service_charge'] ?? 0),
            serviceChargePercentage: $percentage > 0 ? $percentage : null,
            total: (float) ($data['total'] ?? 0),
            raw: $data,
        );
    }
```

> If `BooleanSchema` is unused, omit its import — it is listed only in case you
> prefer a boolean discriminator. The plan uses `EnumSchema` for `status`.

- [ ] **Step 2: Static check**

Run: `docker compose exec app ./vendor/bin/pint --test app/Services/Receipt/PrismReceiptExtractor.php`
Expected: no style errors (run `./vendor/bin/pint` to fix if needed).

- [ ] **Step 3: Confirm the unchanged integration test still passes**

Run: `docker compose exec app php artisan test --filter=ExtractorIntegrationTest`
Expected: PASS (only `resolveCredentials()` is exercised).

- [ ] **Step 4: Manual end-to-end verification (requires API key + queue + reverb)**

With `ANTHROPIC_API_KEY` set and the `queue`/`reverb` services running, create a
session, upload a real receipt, click "Ler conta com IA", and confirm: items come
back categorized; if the model is unsure it asks; answering re-runs and completes;
the summary renders. (`EnumSchema` is the one Prism feature this depends on — if
the provider rejects it, fall back to `StringSchema` with the allowed values named
in the description, since the code already validates the values defensively.)

- [ ] **Step 5: Commit**

```bash
docker compose exec app ./vendor/bin/pint
git add app/Services/Receipt/PrismReceiptExtractor.php
git commit -m "feat(extraction): discriminated Prism schema with category + questions"
```

---

## Task 13: Full suite + Pint green

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `docker compose exec app composer run test`
Expected: all green.

- [ ] **Step 2: Run Pint across the project**

Run: `docker compose exec app ./vendor/bin/pint`
Expected: no outstanding style issues.

- [ ] **Step 3: Commit any Pint fixes**

```bash
git add -A
git commit -m "style: pint" || echo "nothing to commit"
```

---

## Self-Review (completed by plan author)

**Spec coverage:**
- Per-item category → Tasks 1, 3, 5, 6, 9, 11, 12. ✅
- Tip percentage + value, subtotal/total → Tasks 3, 6, 8, 11, 12. ✅
- Agent pause/ask/resume loop → Tasks 2, 6, 7, 10, 12. ✅
- Round cap + forceFinal → Task 6 (cap), Task 12 (prompt). ✅
- Deterministic summary → Tasks 8, 9, 11. ✅
- `needs_clarification` status → Task 2; UI Task 10. ✅
- Tests against SQLite/RefreshDatabase/sync queue → every task. ✅

**Placeholder scan:** No TBD/TODO; every code step has full code. ✅

**Type consistency:** `ExtractionResult::complete(...)` / `requestInput(...)` and
the `needsInput()` predicate are used identically in Tasks 4, 5, 6, 12. Item shape
`{name,quantity,unit_price,total_price,category}` and question shape
`{id,prompt,type,options}` are consistent across the fake, the Prism extractor,
the job, and the Vue template. `clarifications` shape `{round,answered,pending}` is
identical in the job, the controller, and the tests. ✅
