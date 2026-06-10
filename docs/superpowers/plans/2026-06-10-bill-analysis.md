# Bill Analysis (Split Calculation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a session owner run an AI-assisted bill analysis that computes how much each participant must pay (consumed items + equal share of shared food + proportional tip), reconciled exactly to the bill total, with owner-only clarification rounds and a privacy-scoped public view.

**Architecture:** A second AI pipeline running parallel to the existing receipt extraction, reusing its proven shape (queued job + clarification loop + broadcast). The LLM does only fuzzy claim-matching and question generation; a pure-PHP `BillReconciler` does all arithmetic and the reconciliation check. State lives on new, independent columns so re-analysis never disturbs the completed receipt read.

**Tech Stack:** PHP 8.3 / Laravel 13, Prism (OpenAI provider — structured output + audio transcription), Inertia + Vue 3, Reverb (Echo) broadcasting, Pest v4 (SQLite in-memory, `QUEUE_CONNECTION=sync`).

**All commands run inside Docker:** prefix every `php`/`artisan`/`composer`/`npm` command with `docker compose exec app`.

**Design reference:** `docs/superpowers/specs/2026-06-10-bill-analysis-design.md`

---

## File Structure

**New files:**
- `app/Enums/AnalysisStatus.php` — analysis lifecycle states.
- `app/Services/Bill/SplitResult.php` — value object: `complete` or `needs_input`.
- `app/Services/Bill/BillReconciler.php` — pure money math + reconciliation (no LLM, no DB).
- `app/Services/Bill/BillSplitter.php` — interface.
- `app/Services/Bill/FakeBillSplitter.php` — deterministic test impl (canned claims → reconciler).
- `app/Services/Bill/PrismBillSplitter.php` — transcription + LLM claim-match → reconciler.
- `app/Jobs/AnalyzeBill.php` — queued orchestration + persistence + broadcast.
- `app/Events/ReceiptAnalysisUpdated.php` — owner private-channel broadcast.
- `app/Events/BillAnalysisCompleted.php` — public-channel reload signal.
- `app/Http/Requests/UpdateFoodSharedRequest.php`
- `app/Http/Requests/ClarifyAnalysisRequest.php`
- `database/migrations/*_add_analysis_fields_to_bill_sessions_table.php`
- `database/migrations/*_add_analysis_fields_to_session_participants_table.php`

**Modified files:**
- `app/Models/Session.php`, `app/Models/SessionParticipant.php` — fillable/casts.
- `app/Providers/AppServiceProvider.php` — bind `BillSplitter`.
- `app/Http/Controllers/SessionController.php` — `analyze`, `clarifyAnalysis`, `updateFoodShared`.
- `app/Http/Controllers/PublicSessionController.php` — per-device breakdown + public broadcast.
- `routes/web.php`, `routes/channels.php`.
- `resources/js/Pages/Sessions/Show.vue`, `resources/js/Pages/Public/Session.vue`.
- `database/factories/SessionFactory.php`, `database/factories/SessionParticipantFactory.php`.

---

## Task 1: `AnalysisStatus` enum

**Files:**
- Create: `app/Enums/AnalysisStatus.php`
- Test: `tests/Unit/AnalysisStatusTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\AnalysisStatus;

it('has the five lifecycle states', function () {
    expect(AnalysisStatus::Pending->value)->toBe('pending')
        ->and(AnalysisStatus::Processing->value)->toBe('processing')
        ->and(AnalysisStatus::Completed->value)->toBe('completed')
        ->and(AnalysisStatus::NeedsClarification->value)->toBe('needs_clarification')
        ->and(AnalysisStatus::Failed->value)->toBe('failed');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=AnalysisStatusTest`
Expected: FAIL — `Class "App\Enums\AnalysisStatus" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

namespace App\Enums;

enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case NeedsClarification = 'needs_clarification';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=AnalysisStatusTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/AnalysisStatus.php tests/Unit/AnalysisStatusTest.php
git commit -m "feat(analysis): add AnalysisStatus enum"
```

---

## Task 2: Migrations + model fillable/casts

**Files:**
- Create (via artisan): `database/migrations/*_add_analysis_fields_to_bill_sessions_table.php`
- Create (via artisan): `database/migrations/*_add_analysis_fields_to_session_participants_table.php`
- Modify: `app/Models/Session.php`, `app/Models/SessionParticipant.php`
- Test: `tests/Feature/AnalysisModelTest.php`

- [ ] **Step 1: Generate the migration files**

```bash
docker compose exec app php artisan make:migration add_analysis_fields_to_bill_sessions_table
docker compose exec app php artisan make:migration add_analysis_fields_to_session_participants_table
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\AnalysisStatus;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts the new analysis fields on the session', function () {
    $session = Session::factory()->for(User::factory())->create([
        'food_shared' => false,
        'analysis_status' => AnalysisStatus::NeedsClarification,
        'analysis_clarifications' => ['round' => 1, 'answered' => [], 'pending' => []],
        'analysis_result' => ['participants' => []],
    ]);

    $fresh = $session->fresh();

    expect($fresh->food_shared)->toBeFalse()
        ->and($fresh->analysis_status)->toBe(AnalysisStatus::NeedsClarification)
        ->and($fresh->analysis_clarifications)->toBeArray()
        ->and($fresh->analysis_result)->toBeArray();
});

it('defaults a new session to shared food and pending analysis', function () {
    $session = Session::factory()->for(User::factory())->create();

    expect($session->fresh()->food_shared)->toBeTrue()
        ->and($session->fresh()->analysis_status)->toBe(AnalysisStatus::Pending);
});

it('casts the new analysis fields on the participant', function () {
    $session = Session::factory()->for(User::factory())->create();
    $participant = SessionParticipant::factory()->for($session, 'session')->create([
        'amount_due' => 123.45,
        'breakdown' => ['total' => 123.45, 'items' => []],
        'transcript' => 'consumi uma cerveja',
    ]);

    $fresh = $participant->fresh();

    expect((float) $fresh->amount_due)->toBe(123.45)
        ->and($fresh->breakdown)->toBeArray()
        ->and($fresh->transcript)->toBe('consumi uma cerveja');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=AnalysisModelTest`
Expected: FAIL — unknown column `food_shared`.

- [ ] **Step 4: Write the bill_sessions migration**

In `*_add_analysis_fields_to_bill_sessions_table.php`:

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
            $table->boolean('food_shared')->default(true)->after('total');
            $table->string('analysis_status')->default('pending')->after('food_shared');
            $table->json('analysis_clarifications')->nullable()->after('analysis_status');
            $table->json('analysis_result')->nullable()->after('analysis_clarifications');
            $table->text('analysis_failure_reason')->nullable()->after('analysis_result');
            $table->timestamp('analyzed_at')->nullable()->after('analysis_failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'food_shared',
                'analysis_status',
                'analysis_clarifications',
                'analysis_result',
                'analysis_failure_reason',
                'analyzed_at',
            ]);
        });
    }
};
```

- [ ] **Step 5: Write the session_participants migration**

In `*_add_analysis_fields_to_session_participants_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_participants', function (Blueprint $table) {
            $table->text('transcript')->nullable()->after('audio_duration');
            $table->decimal('amount_due', 10, 2)->nullable()->after('transcript');
            $table->json('breakdown')->nullable()->after('amount_due');
        });
    }

    public function down(): void
    {
        Schema::table('session_participants', function (Blueprint $table) {
            $table->dropColumn(['transcript', 'amount_due', 'breakdown']);
        });
    }
};
```

- [ ] **Step 6: Update `Session` model**

Add to `$fillable` (after `'clarifications',`):

```php
        'food_shared',
        'analysis_status',
        'analysis_clarifications',
        'analysis_result',
        'analysis_failure_reason',
        'analyzed_at',
```

Add `use App\Enums\AnalysisStatus;` at the top, and add to the `casts()` array:

```php
            'food_shared' => 'boolean',
            'analysis_status' => AnalysisStatus::class,
            'analysis_clarifications' => 'array',
            'analysis_result' => 'array',
            'analyzed_at' => 'datetime',
```

- [ ] **Step 7: Update `SessionParticipant` model**

Add to `$fillable` (after `'audio_duration',`):

```php
        'transcript',
        'amount_due',
        'breakdown',
```

Add to the `casts()` array:

```php
            'amount_due' => 'decimal:2',
            'breakdown' => 'array',
```

- [ ] **Step 8: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=AnalysisModelTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add database/migrations app/Models/Session.php app/Models/SessionParticipant.php tests/Feature/AnalysisModelTest.php
git commit -m "feat(analysis): add analysis columns to sessions and participants"
```

---

## Task 3: `SplitResult` value object

**Files:**
- Create: `app/Services/Bill/SplitResult.php`
- Test: `tests/Unit/SplitResultTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Bill\SplitResult;

it('builds a complete result', function () {
    $allocations = [
        ['participant_id' => 'p1', 'name' => 'William', 'items' => [], 'shared_food_share' => 0.0, 'subtotal' => 10.0, 'tip' => 1.0, 'total' => 11.0],
    ];

    $result = SplitResult::complete($allocations, ['ok' => true]);

    expect($result->needsInput())->toBeFalse()
        ->and($result->allocations)->toBe($allocations);
});

it('builds a needs-input result', function () {
    $questions = [['id' => 'q1', 'prompt' => 'Quem bebeu a Heineken?', 'type' => 'text', 'options' => []]];

    $result = SplitResult::requestInput($questions, ['raw' => 1]);

    expect($result->needsInput())->toBeTrue()
        ->and($result->questions)->toBe($questions)
        ->and($result->allocations)->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=SplitResultTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the value object**

```php
<?php

namespace App\Services\Bill;

class SplitResult
{
    /**
     * @param  array<int, array<string, mixed>>  $allocations
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly array $allocations = [],
        public readonly array $questions = [],
        public readonly array $raw = [],
    ) {}

    public function needsInput(): bool
    {
        return $this->status === 'needs_input';
    }

    /**
     * @param  array<int, array<string, mixed>>  $allocations
     * @param  array<string, mixed>  $raw
     */
    public static function complete(array $allocations, array $raw = []): self
    {
        return new self(status: 'complete', allocations: $allocations, raw: $raw);
    }

    /**
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    public static function requestInput(array $questions, array $raw = []): self
    {
        return new self(status: 'needs_input', questions: $questions, raw: $raw);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=SplitResultTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bill/SplitResult.php tests/Unit/SplitResultTest.php
git commit -m "feat(analysis): add SplitResult value object"
```

---

## Task 4: `BillReconciler` (pure money math + reconciliation)

This is the heart of the feature. It takes receipt items, the per-participant claims the
LLM matched, the shared-food flag, the tip percentage, and the bill total, and returns a
`SplitResult` — either complete allocations or clarification questions. No LLM, no DB:
fully deterministic and unit-tested.

**Input shapes:**
- `$items`: `array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: 'food'|'drink'}>`
- `$participants`: `array<int, array{id: string, name: string}>`
- `$claims`: `array<int, array{participant_id: string, items: array<int, array{name: string, quantity: float}>}>`

**Files:**
- Create: `app/Services/Bill/BillReconciler.php`
- Test: `tests/Unit/BillReconcilerTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Services\Bill\BillReconciler;

function receiptFixture(): array
{
    return [
        ['name' => 'Parmegiana', 'quantity' => 1.0, 'unit_price' => 119.90, 'total_price' => 119.90, 'category' => 'food'],
        ['name' => 'Bife a Cavalo', 'quantity' => 3.0, 'unit_price' => 50.00, 'total_price' => 150.00, 'category' => 'food'],
        ['name' => 'Heineken', 'quantity' => 3.0, 'unit_price' => 9.90, 'total_price' => 29.70, 'category' => 'drink'],
        ['name' => 'Moscow Mule', 'quantity' => 2.0, 'unit_price' => 31.90, 'total_price' => 63.80, 'category' => 'drink'],
    ];
}

function participantsFixture(): array
{
    return [
        ['id' => 'w', 'name' => 'William'],
        ['id' => 'c', 'name' => 'Camila'],
    ];
}

it('reconciles the worked example with shared food', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [
            ['name' => 'Moscow Mule', 'quantity' => 1.0],
            ['name' => 'Heineken', 'quantity' => 2.0],
        ]],
        ['participant_id' => 'c', 'items' => [
            ['name' => 'Heineken', 'quantity' => 1.0],
            ['name' => 'Moscow Mule', 'quantity' => 1.0],
        ]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeFalse();

    $byId = collect($result->allocations)->keyBy('participant_id');

    // Shared food = 119.90 + 150.00 = 269.90, split equally = 134.95 each.
    expect($byId['w']['shared_food_share'])->toBe(134.95)
        ->and($byId['c']['shared_food_share'])->toBe(134.95);

    // William: 31.90 + 2*9.90 + 134.95 = 186.65 ; tip 10% = 18.67 (rounded) ; total 205.32
    expect($byId['w']['subtotal'])->toBe(186.65)
        ->and($byId['w']['total'])->toBe(205.32);

    // Camila: 9.90 + 31.90 + 134.95 = 176.75 ; tip 17.68 ; total 194.43 (rounding to the cent)
    expect($byId['c']['subtotal'])->toBe(176.75);

    // Grand total reconciles to bill total within a cent.
    $grand = collect($result->allocations)->sum('total');
    expect(abs($grand - 399.74))->toBeLessThanOrEqual(0.01);
});

it('asks a question when a drink is left unclaimed', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [['name' => 'Moscow Mule', 'quantity' => 1.0]]],
        ['participant_id' => 'c', 'items' => [['name' => 'Moscow Mule', 'quantity' => 1.0]]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeTrue()
        ->and($result->questions)->not->toBeEmpty()
        ->and($result->questions[0]['prompt'])->toContain('Heineken');
});

it('asks a question when food is unclaimed and food is not shared', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [
            ['name' => 'Moscow Mule', 'quantity' => 1.0], ['name' => 'Heineken', 'quantity' => 2.0],
        ]],
        ['participant_id' => 'c', 'items' => [
            ['name' => 'Moscow Mule', 'quantity' => 1.0], ['name' => 'Heineken', 'quantity' => 1.0],
        ]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: false,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeTrue()
        ->and($result->questions[0]['prompt'])->toContain('Parmegiana');
});

it('asks a question when a participant over-claims beyond receipt quantity', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [['name' => 'Moscow Mule', 'quantity' => 5.0]]],
        ['participant_id' => 'c', 'items' => []],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: false,
    );

    expect($result->needsInput())->toBeTrue();
});

it('always closes on the final forced round by sharing all leftovers', function () {
    $claims = [
        ['participant_id' => 'w', 'items' => [['name' => 'Moscow Mule', 'quantity' => 1.0]]],
        ['participant_id' => 'c', 'items' => [['name' => 'Moscow Mule', 'quantity' => 1.0]]],
    ];

    $result = (new BillReconciler)->reconcile(
        items: receiptFixture(),
        participants: participantsFixture(),
        claims: $claims,
        foodShared: true,
        serviceChargePercentage: 10.0,
        total: 399.74,
        forceFinal: true,
    );

    expect($result->needsInput())->toBeFalse();
    $grand = collect($result->allocations)->sum('total');
    expect(abs($grand - 399.74))->toBeLessThanOrEqual(0.02);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=BillReconcilerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the reconciler**

```php
<?php

namespace App\Services\Bill;

use Illuminate\Support\Str;

class BillReconciler
{
    /**
     * Allowed rounding drift (in BRL) when checking the per-person sum against the total.
     */
    private const TOLERANCE = 0.02;

    /**
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: string}>  $items
     * @param  array<int, array{id: string, name: string}>  $participants
     * @param  array<int, array{participant_id: string, items: array<int, array{name: string, quantity: float}>}>  $claims
     */
    public function reconcile(
        array $items,
        array $participants,
        array $claims,
        bool $foodShared,
        float $serviceChargePercentage,
        float $total,
        bool $forceFinal,
    ): SplitResult {
        // Index receipt items by normalized name; track remaining (leftover) quantity.
        $catalog = [];
        foreach ($items as $item) {
            $key = $this->normalize($item['name']);
            $catalog[$key] = [
                'name' => $item['name'],
                'unit_price' => (float) $item['unit_price'],
                'category' => $item['category'],
                'remaining' => (float) $item['quantity'],
            ];
        }

        // Per-participant consumed subtotal (claimed items only, before shared food).
        $consumed = [];
        foreach ($participants as $p) {
            $consumed[$p['id']] = ['name' => $p['name'], 'items' => [], 'amount' => 0.0];
        }

        $questions = [];

        // 1. Subtract claimed units from the leftover pool.
        foreach ($claims as $claim) {
            $pid = $claim['participant_id'];
            if (! isset($consumed[$pid])) {
                continue;
            }

            foreach ($claim['items'] as $line) {
                $key = $this->normalize($line['name']);
                $qty = (float) $line['quantity'];

                if (! isset($catalog[$key])) {
                    $questions[] = $this->question(
                        "O item \"{$line['name']}\" atribuído a {$consumed[$pid]['name']} não está na conta. O que ele consumiu de fato?"
                    );

                    continue;
                }

                $catalog[$key]['remaining'] -= $qty;
                $lineTotal = round($qty * $catalog[$key]['unit_price'], 2);
                $consumed[$pid]['amount'] = round($consumed[$pid]['amount'] + $lineTotal, 2);
                $consumed[$pid]['items'][] = [
                    'name' => $catalog[$key]['name'],
                    'quantity' => $qty,
                    'unit_price' => $catalog[$key]['unit_price'],
                    'total_price' => $lineTotal,
                    'category' => $catalog[$key]['category'],
                ];
            }
        }

        // 2. Over-claim check: any negative leftover means more was claimed than exists.
        foreach ($catalog as $entry) {
            if ($entry['remaining'] < -0.001) {
                $questions[] = $this->question(
                    "Foi atribuído mais \"{$entry['name']}\" do que existe na conta. Quem consumiu o quê?"
                );
            }
        }

        // 3. Leftover handling. Drinks always need an owner; food only when not shared.
        //    On the final forced round, everything leftover is shared instead of asked.
        $sharedFoodValue = 0.0;
        foreach ($catalog as $entry) {
            $left = $entry['remaining'];
            if ($left <= 0.001) {
                continue;
            }

            $value = round($left * $entry['unit_price'], 2);
            $isFood = $entry['category'] === 'food';

            if ($forceFinal) {
                $sharedFoodValue = round($sharedFoodValue + $value, 2);

                continue;
            }

            if ($isFood && $foodShared) {
                $sharedFoodValue = round($sharedFoodValue + $value, 2);

                continue;
            }

            $kind = $isFood ? 'comida' : 'bebida';
            $questions[] = $this->question(
                "Sobrou {$this->qty($left)}x \"{$entry['name']}\" ({$kind}) sem dono. Quem consumiu?"
            );
        }

        if ($questions !== [] && ! $forceFinal) {
            return SplitResult::requestInput(
                array_values($questions),
                ['leftover_questions' => count($questions)],
            );
        }

        // 4. Split shared food equally across all participants.
        $share = count($participants) > 0
            ? round($sharedFoodValue / count($participants), 2)
            : 0.0;

        // 5. Build allocations with proportional tip, fixing rounding drift on the last row.
        $allocations = [];
        $running = 0.0;
        $last = array_key_last($participants);

        foreach ($participants as $index => $p) {
            $pid = $p['id'];
            $subtotal = round($consumed[$pid]['amount'] + $share, 2);
            $tip = round($subtotal * $serviceChargePercentage / 100, 2);
            $rowTotal = round($subtotal + $tip, 2);

            $allocations[] = [
                'participant_id' => $pid,
                'name' => $consumed[$pid]['name'],
                'items' => $consumed[$pid]['items'],
                'shared_food_share' => $share,
                'subtotal' => $subtotal,
                'tip' => $tip,
                'total' => $rowTotal,
            ];

            $running = round($running + $rowTotal, 2);
        }

        // 6. Reconcile against the bill total; nudge the last row to absorb cent-level drift.
        $drift = round($total - $running, 2);
        if (abs($drift) > 0.001 && $allocations !== []) {
            $allocations[count($allocations) - 1]['total'] =
                round($allocations[count($allocations) - 1]['total'] + $drift, 2);
            $running = round($running + $drift, 2);
        }

        if (! $forceFinal && abs($total - $running) > self::TOLERANCE) {
            return SplitResult::requestInput(
                [$this->question(
                    'A soma do que cada um consumiu não fechou com o total da conta. '
                    .'Algum item ficou sem dono ou foi contado a mais?'
                )],
                ['expected_total' => $total, 'computed_total' => $running],
            );
        }

        return SplitResult::complete($allocations, [
            'shared_food_value' => $sharedFoodValue,
            'computed_total' => $running,
        ]);
    }

    private function normalize(string $name): string
    {
        return Str::of($name)->lower()->ascii()->squish()->value();
    }

    /**
     * @return array{id: string, prompt: string, type: string, options: array<int, string>}
     */
    private function question(string $prompt): array
    {
        return [
            'id' => (string) Str::uuid(),
            'prompt' => $prompt,
            'type' => 'text',
            'options' => [],
        ];
    }

    private function qty(float $value): string
    {
        return fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : rtrim(number_format($value, 2, ',', ''), '0');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=BillReconcilerTest`
Expected: PASS (all 5). If the worked-example cent values differ, adjust the test's expected
`total` assertions to the reconciler's rounding — the grand-total reconciliation assertion is
the binding one.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bill/BillReconciler.php tests/Unit/BillReconcilerTest.php
git commit -m "feat(analysis): add deterministic BillReconciler with reconciliation"
```

---

## Task 5: `BillSplitter` interface + `FakeBillSplitter` + binding

`FakeBillSplitter` returns deterministic claims (William/Camila example) and runs them
through the real `BillReconciler`, so job/controller tests exercise real money math without
the network.

**Files:**
- Create: `app/Services/Bill/BillSplitter.php`
- Create: `app/Services/Bill/FakeBillSplitter.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/FakeBillSplitterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Session;
use App\Models\User;
use App\Services\Bill\BillSplitter;
use App\Services\Bill\FakeBillSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is the bound implementation in tests', function () {
    expect(app(BillSplitter::class))->toBeInstanceOf(FakeBillSplitter::class);
});

it('returns complete allocations for the canned example', function () {
    $session = Session::factory()->for(User::factory())->create([
        'service_charge_percentage' => 10.0,
        'total' => 399.74,
    ]);

    $participants = [
        ['id' => 'w', 'name' => 'William'],
        ['id' => 'c', 'name' => 'Camila'],
    ];

    $result = app(BillSplitter::class)->split($session, $participants, true, [], false);

    expect($result->needsInput())->toBeFalse()
        ->and($result->allocations)->toHaveCount(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=FakeBillSplitterTest`
Expected: FAIL — interface/class not found.

- [ ] **Step 3: Write the interface**

```php
<?php

namespace App\Services\Bill;

use App\Models\Session;

interface BillSplitter
{
    /**
     * Compute per-participant amounts, or return clarification questions.
     *
     * @param  array<int, array{id: string, name: string}>  $participants
     * @param  array<int, array{question: string, answer: string}>  $answered  Prior Q&A fed back to the model.
     * @param  bool  $forceFinal  When true, must return complete allocations (no more questions).
     */
    public function split(Session $session, array $participants, bool $foodShared, array $answered = [], bool $forceFinal = false): SplitResult;
}
```

- [ ] **Step 4: Write the fake**

```php
<?php

namespace App\Services\Bill;

use App\Models\Session;

class FakeBillSplitter implements BillSplitter
{
    public function split(Session $session, array $participants, bool $foodShared, array $answered = [], bool $forceFinal = false): SplitResult
    {
        // Canned claims matching the worked example; only applied to the first two participants.
        $ids = array_column($participants, 'id');
        $claims = [];

        if (isset($ids[0])) {
            $claims[] = ['participant_id' => $ids[0], 'items' => [
                ['name' => 'Moscow Mule', 'quantity' => 1.0],
                ['name' => 'Heineken', 'quantity' => 2.0],
            ]];
        }
        if (isset($ids[1])) {
            $claims[] = ['participant_id' => $ids[1], 'items' => [
                ['name' => 'Heineken', 'quantity' => 1.0],
                ['name' => 'Moscow Mule', 'quantity' => 1.0],
            ]];
        }

        $items = $session->items->map(fn ($i) => [
            'name' => $i->name,
            'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'total_price' => (float) $i->total_price,
            'category' => $i->category?->value ?? 'food',
        ])->all();

        return (new BillReconciler)->reconcile(
            items: $items,
            participants: $participants,
            claims: $claims,
            foodShared: $foodShared,
            serviceChargePercentage: (float) $session->service_charge_percentage,
            total: (float) $session->total,
            forceFinal: $forceFinal,
        );
    }
}
```

- [ ] **Step 5: Bind in `AppServiceProvider::register()`**

Add imports:

```php
use App\Services\Bill\BillSplitter;
use App\Services\Bill\FakeBillSplitter;
use App\Services\Bill\PrismBillSplitter;
```

Add binding (production impl created in Task 6; bind it now and the test overrides it):

```php
        $this->app->bind(
            BillSplitter::class,
            PrismBillSplitter::class,
        );
```

- [ ] **Step 6: Override the binding in tests**

In `tests/Pest.php` (or the existing `TestCase` boot), bind the fake for the whole suite.
Add to `tests/Pest.php` inside the `uses(...)` block's `beforeEach`, or create one:

```php
// tests/Pest.php — register near the top-level uses() configuration
use App\Services\Bill\BillSplitter;
use App\Services\Bill\FakeBillSplitter;
use App\Services\Receipt\FakeReceiptExtractor;
use App\Services\Receipt\ReceiptExtractor;

uses()->beforeEach(function () {
    app()->bind(ReceiptExtractor::class, FakeReceiptExtractor::class);
    app()->bind(BillSplitter::class, FakeBillSplitter::class);
})->in('Feature', 'Unit');
```

> Check `tests/Pest.php` first: if a `ReceiptExtractor` fake binding already exists there,
> add only the `BillSplitter` line alongside it rather than duplicating the block.

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=FakeBillSplitterTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Bill/BillSplitter.php app/Services/Bill/FakeBillSplitter.php app/Providers/AppServiceProvider.php tests/Pest.php tests/Unit/FakeBillSplitterTest.php
git commit -m "feat(analysis): add BillSplitter interface, fake impl, and binding"
```

---

## Task 6: `PrismBillSplitter` (transcription + LLM claim-match → reconciler)

No automated test (it calls the network). It is exercised end-to-end manually; the Fake
covers job/controller tests. Provide complete, convention-matching code modeled on
`PrismReceiptExtractor`.

**Files:**
- Create: `app/Services/Bill/PrismBillSplitter.php`

- [ ] **Step 1: Write the implementation**

```php
<?php

namespace App\Services\Bill;

use App\Models\Integration;
use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Audio;

class PrismBillSplitter implements BillSplitter
{
    public function split(Session $session, array $participants, bool $foodShared, array $answered = [], bool $forceFinal = false): SplitResult
    {
        Log::info('[Service][PrismBillSplitter][split] Inicio da execusão.', [
            'session_id' => $session->id,
            'participantes' => count($participants),
            'food_shared' => $foodShared,
            'force_final' => $forceFinal,
        ]);

        $session->loadMissing('items', 'participants');

        // 1. Transcribe any audio that is not already cached.
        $transcripts = $this->transcribeParticipants($session);

        // 2. Ask the model to MATCH claims to receipt items (or raise questions). No math.
        $claims = $this->matchClaims($session, $transcripts, $answered, $forceFinal);

        if ($claims->needsInput() && ! $forceFinal) {
            return $claims;
        }

        // 3. Deterministic money + reconciliation in PHP.
        $items = $session->items->map(fn ($i) => [
            'name' => $i->name,
            'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'total_price' => (float) $i->total_price,
            'category' => $i->category?->value ?? 'food',
        ])->all();

        return (new BillReconciler)->reconcile(
            items: $items,
            participants: $participants,
            claims: $claims->raw['claims'] ?? [],
            foodShared: $foodShared,
            serviceChargePercentage: (float) $session->service_charge_percentage,
            total: (float) $session->total,
            forceFinal: $forceFinal,
        );
    }

    /**
     * @return array<string, string>  participant_id => combined transcript+text
     */
    private function transcribeParticipants(Session $session): array
    {
        $creds = $this->resolveCredentials();
        $out = [];

        foreach ($session->participants as $participant) {
            $text = (string) $participant->text;

            if (filled($participant->audio_path) && blank($participant->transcript)) {
                try {
                    $path = Storage::disk('public')->path($participant->audio_path);
                    $response = Prism::audio()
                        ->using(Provider::OpenAI, $creds['audio_model'])
                        ->withInput(Audio::fromLocalPath($path))
                        ->asText();
                    $participant->forceFill(['transcript' => $response->text])->save();
                } catch (\Throwable $e) {
                    Log::warning('[Service][PrismBillSplitter][transcribeParticipants] Falha ao transcrever áudio.', [
                        'participant_id' => $participant->id,
                        'erro' => $e->getMessage(),
                    ]);
                }
            }

            $out[$participant->id] = trim($text.' '.(string) $participant->transcript);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $transcripts
     * @param  array<int, array{question: string, answer: string}>  $answered
     */
    private function matchClaims(Session $session, array $transcripts, array $answered, bool $forceFinal): SplitResult
    {
        $schema = new ObjectSchema(
            name: 'split',
            description: 'Atribuição do que cada participante consumiu, OU perguntas quando houver dúvida',
            properties: [
                new EnumSchema('status', 'Use "needs_input" se tiver QUALQUER dúvida; senão "complete"', ['complete', 'needs_input']),
                new ArraySchema(
                    name: 'questions',
                    description: 'Perguntas ao dono quando status = needs_input (senão lista vazia)',
                    items: new ObjectSchema(
                        name: 'question',
                        description: 'Uma pergunta de esclarecimento',
                        properties: [
                            new StringSchema('id', 'Identificador curto e único (ex.: q1)'),
                            new StringSchema('prompt', 'A pergunta em português'),
                            new EnumSchema('type', 'choice ou text', ['choice', 'text']),
                            new ArraySchema('options', 'Opções quando type = choice (senão vazio)', new StringSchema('option', 'Uma opção')),
                        ],
                        requiredFields: ['id', 'prompt', 'type', 'options'],
                    ),
                ),
                new ArraySchema(
                    name: 'claims',
                    description: 'O que cada participante consumiu quando status = complete (senão vazio)',
                    items: new ObjectSchema(
                        name: 'claim',
                        description: 'Itens consumidos por um participante',
                        properties: [
                            new StringSchema('participant_id', 'ID do participante'),
                            new ArraySchema(
                                name: 'items',
                                description: 'Itens que esta pessoa consumiu',
                                items: new ObjectSchema(
                                    name: 'claim_item',
                                    description: 'Um item consumido',
                                    properties: [
                                        new StringSchema('name', 'Nome do item exatamente como aparece na conta'),
                                        new NumberSchema('quantity', 'Quantidade consumida por esta pessoa'),
                                    ],
                                    requiredFields: ['name', 'quantity'],
                                ),
                            ),
                        ],
                        requiredFields: ['participant_id', 'items'],
                    ),
                ),
            ],
            requiredFields: ['status', 'questions', 'claims'],
        );

        $itemLines = $session->items
            ->map(fn ($i) => "- {$i->name} (qtd {$i->quantity}, {$i->category?->value})")
            ->implode("\n");

        $peopleLines = collect($transcripts)
            ->map(function (string $said, string $id) use ($session) {
                $name = $session->participants->firstWhere('id', $id)?->name ?? $id;

                return "- participant_id={$id} nome={$name}: \"{$said}\"";
            })
            ->implode("\n");

        $prompt = "Você está dividindo uma conta de bar/restaurante. Itens da conta:\n{$itemLines}\n\n"
            ."Cada participante disse o que consumiu:\n{$peopleLines}\n\n"
            .'Para cada participante, liste em "claims" os itens que ELE consumiu, usando o '
            .'nome EXATO do item na conta e a quantidade. NÃO calcule valores nem gorjeta. '
            .'NÃO invente: se algo estiver ambíguo (ex.: alguém citou um item que não está na '
            .'conta, ou uma quantidade não bate), retorne status "needs_input" com perguntas '
            .'objetivas em "questions" e deixe "claims" vazio. Caso contrário, status "complete".';

        if ($answered !== []) {
            $prompt .= "\n\nO dono já respondeu:\n";
            foreach ($answered as $qa) {
                $prompt .= '- '.$qa['question'].' => '.$qa['answer']."\n";
            }
        }

        if ($forceFinal) {
            $prompt .= "\n\nEsta é a rodada final: NÃO faça mais perguntas. Use seu melhor julgamento "
                .'e retorne status "complete" com os claims que conseguir inferir.';
        }

        $response = Prism::structured()
            ->using(Provider::OpenAI, $this->resolveCredentials()['model'])
            ->withSchema($schema)
            ->withProviderOptions(['use_tool_calling' => true])
            ->withPrompt($prompt)
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

            return SplitResult::requestInput($questions, $data);
        }

        $claims = array_map(fn (array $c): array => [
            'participant_id' => (string) ($c['participant_id'] ?? ''),
            'items' => array_map(fn (array $i): array => [
                'name' => (string) ($i['name'] ?? ''),
                'quantity' => (float) ($i['quantity'] ?? 0),
            ], $c['items'] ?? []),
        ], $data['claims'] ?? []);

        // Carry claims through raw so split() can hand them to the reconciler.
        return SplitResult::complete([], ['claims' => $claims] + $data);
    }

    /**
     * @return array{model: string, audio_model: string}
     */
    private function resolveCredentials(): array
    {
        $integration = Integration::current();

        if (filled($integration->api_key)) {
            config(['prism.providers.openai.api_key' => $integration->api_key]);
        }

        return [
            'model' => filled($integration->receipt_model)
                ? $integration->receipt_model
                : config('services.openai.receipt_model'),
            'audio_model' => filled($integration->audio_model)
                ? $integration->audio_model
                : config('services.openai.audio_model'),
        ];
    }
}
```

- [ ] **Step 2: Sanity check it compiles**

Run: `docker compose exec app php -l app/Services/Bill/PrismBillSplitter.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Confirm the suite still binds the fake (no network in tests)**

Run: `docker compose exec app php artisan test --filter=FakeBillSplitterTest`
Expected: PASS (still resolves `FakeBillSplitter`).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Bill/PrismBillSplitter.php
git commit -m "feat(analysis): add PrismBillSplitter (transcription + LLM claim matching)"
```

---

## Task 7: Broadcast events + public channel

**Files:**
- Create: `app/Events/ReceiptAnalysisUpdated.php`
- Create: `app/Events/BillAnalysisCompleted.php`
- Modify: `routes/channels.php`
- Test: `tests/Unit/AnalysisEventsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Events\BillAnalysisCompleted;
use App\Events\ReceiptAnalysisUpdated;

it('broadcasts analysis updates on the owner private channel', function () {
    $event = new ReceiptAnalysisUpdated('sess-1', 'completed');

    expect($event->broadcastOn()->name)->toBe('private-bill-session.sess-1')
        ->and($event->broadcastAs())->toBe('analysis.updated');
});

it('broadcasts a public completion signal on the public channel', function () {
    $event = new BillAnalysisCompleted('sess-1');

    expect($event->broadcastOn()->name)->toBe('bill-session.sess-1.public')
        ->and($event->broadcastAs())->toBe('analysis.completed');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=AnalysisEventsTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write `ReceiptAnalysisUpdated`**

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReceiptAnalysisUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $status,
        public ?string $failureReason = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('bill-session.'.$this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'analysis.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'status' => $this->status,
            'failureReason' => $this->failureReason,
        ];
    }
}
```

- [ ] **Step 4: Write `BillAnalysisCompleted`**

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillAnalysisCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $sessionId) {}

    public function broadcastOn(): Channel
    {
        return new Channel('bill-session.'.$this->sessionId.'.public');
    }

    public function broadcastAs(): string
    {
        return 'analysis.completed';
    }
}
```

- [ ] **Step 5: Register the public channel (no auth) in `routes/channels.php`**

Append:

```php
// Public channel: only signals "reload", carries no per-person data.
Broadcast::channel('bill-session.{sessionId}.public', fn () => true);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=AnalysisEventsTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Events/ReceiptAnalysisUpdated.php app/Events/BillAnalysisCompleted.php routes/channels.php tests/Unit/AnalysisEventsTest.php
git commit -m "feat(analysis): add analysis broadcast events and public channel"
```

---

## Task 8: `AnalyzeBill` job

**Files:**
- Create: `app/Jobs/AnalyzeBill.php`
- Test: `tests/Feature/AnalyzeBillJobTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\AnalysisStatus;
use App\Enums\ExtractionStatus;
use App\Events\BillAnalysisCompleted;
use App\Events\ReceiptAnalysisUpdated;
use App\Jobs\AnalyzeBill;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function seedSplittableSession(): Session
{
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'subtotal' => 363.40,
        'service_charge' => 36.34,
        'service_charge_percentage' => 10.0,
        'total' => 399.74,
        'food_shared' => true,
        'analysis_status' => AnalysisStatus::Processing,
    ]);

    foreach ([
        ['Parmegiana', 1, 119.90, 119.90, 'food'],
        ['Bife a Cavalo', 3, 50.00, 150.00, 'food'],
        ['Heineken', 3, 9.90, 29.70, 'drink'],
        ['Moscow Mule', 2, 31.90, 63.80, 'drink'],
    ] as $i => [$name, $qty, $unit, $line, $cat]) {
        SessionItem::factory()->for($session, 'session')->create([
            'name' => $name, 'quantity' => $qty, 'unit_price' => $unit,
            'total_price' => $line, 'category' => $cat, 'position' => $i + 1,
        ]);
    }

    SessionParticipant::factory()->for($session, 'session')->create(['name' => 'William', 'text' => '1 moscow mule e 2 heineken']);
    SessionParticipant::factory()->for($session, 'session')->create(['name' => 'Camila', 'text' => '1 heineken e 1 moscow mule']);

    return $session->load('items', 'participants');
}

it('completes analysis and persists per-participant amounts', function () {
    Event::fake([ReceiptAnalysisUpdated::class, BillAnalysisCompleted::class]);

    $session = seedSplittableSession();

    (new AnalyzeBill($session))->handle(app(\App\Services\Bill\BillSplitter::class));

    $session->refresh()->load('participants');

    expect($session->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($session->analysis_result)->toBeArray()
        ->and($session->analyzed_at)->not->toBeNull();

    $totals = $session->participants->pluck('amount_due')->map(fn ($v) => (float) $v);
    expect($totals->filter())->toHaveCount(2)
        ->and(abs($totals->sum() - 399.74))->toBeLessThanOrEqual(0.02);

    Event::assertDispatched(ReceiptAnalysisUpdated::class);
    Event::assertDispatched(BillAnalysisCompleted::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=AnalyzeBillJobTest`
Expected: FAIL — `App\Jobs\AnalyzeBill` not found.

- [ ] **Step 3: Write the job**

```php
<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Events\BillAnalysisCompleted;
use App\Events\ReceiptAnalysisUpdated;
use App\Models\Session;
use App\Services\Bill\BillSplitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeBill implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Hard cap on clarification rounds before forcing a final result. */
    public const MAX_ROUNDS = 2;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function __construct(public Session $session) {}

    public function handle(BillSplitter $splitter): void
    {
        Log::info('[Job][AnalyzeBill][handle] Inicio da execusão.', [
            'session_id' => $this->session->id,
        ]);

        $this->session->loadMissing('participants', 'items');

        $clarifications = $this->session->analysis_clarifications ?? [];
        $answered = $clarifications['answered'] ?? [];
        $round = $clarifications['round'] ?? 0;
        $forceFinal = $round >= self::MAX_ROUNDS;

        $participants = $this->session->participants
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->all();

        $result = $splitter->split(
            $this->session,
            $participants,
            (bool) $this->session->food_shared,
            $answered,
            $forceFinal,
        );

        if ($result->needsInput() && ! $forceFinal) {
            $this->session->forceFill([
                'analysis_status' => AnalysisStatus::NeedsClarification,
                'analysis_clarifications' => [
                    'round' => $round,
                    'answered' => $answered,
                    'pending' => $result->questions,
                ],
                'analysis_failure_reason' => null,
            ])->save();

            event(new ReceiptAnalysisUpdated($this->session->id, AnalysisStatus::NeedsClarification->value));

            Log::info('[Job][AnalyzeBill][handle] Análise precisa de esclarecimento. Fim da execusão.', [
                'session_id' => $this->session->id,
                'perguntas' => count($result->questions),
                'round' => $round,
            ]);

            return;
        }

        // Persist each participant's amount + breakdown.
        $byId = collect($result->allocations)->keyBy('participant_id');
        foreach ($this->session->participants as $participant) {
            $alloc = $byId->get($participant->id);
            if ($alloc === null) {
                continue;
            }
            $participant->forceFill([
                'amount_due' => $alloc['total'],
                'breakdown' => $alloc,
            ])->save();
        }

        $this->session->forceFill([
            'analysis_status' => AnalysisStatus::Completed,
            'analysis_result' => ['participants' => $result->allocations],
            'analysis_clarifications' => null,
            'analysis_failure_reason' => null,
            'analyzed_at' => now(),
        ])->save();

        event(new ReceiptAnalysisUpdated($this->session->id, AnalysisStatus::Completed->value));
        event(new BillAnalysisCompleted($this->session->id));

        Log::info('[Job][AnalyzeBill][handle] Análise concluída. Fim da execusão.', [
            'session_id' => $this->session->id,
            'participantes' => count($result->allocations),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('[Job][AnalyzeBill][failed] Inicio da execusão: job de análise falhou.', [
            'session_id' => $this->session->id,
            'erro' => $exception->getMessage(),
        ]);

        $this->session->forceFill([
            'analysis_status' => AnalysisStatus::Failed,
            'analysis_failure_reason' => $exception->getMessage(),
        ])->save();

        event(new ReceiptAnalysisUpdated(
            $this->session->id,
            AnalysisStatus::Failed->value,
            $exception->getMessage(),
        ));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=AnalyzeBillJobTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/AnalyzeBill.php tests/Feature/AnalyzeBillJobTest.php
git commit -m "feat(analysis): add AnalyzeBill job with clarification loop"
```

---

## Task 9: Form Requests

**Files:**
- Create: `app/Http/Requests/UpdateFoodSharedRequest.php`
- Create: `app/Http/Requests/ClarifyAnalysisRequest.php`

- [ ] **Step 1: Write `UpdateFoodSharedRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFoodSharedRequest extends FormRequest
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
            'food_shared' => ['required', 'boolean'],
        ];
    }
}
```

- [ ] **Step 2: Write `ClarifyAnalysisRequest`**

Mirrors `ClarifyExtractionRequest`, but validates against the `analysis_clarifications`
pending list.

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ClarifyAnalysisRequest extends FormRequest
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $answers = $this->input('answers', []);

            foreach (data_get($this->route('session'), 'analysis_clarifications.pending', []) as $question) {
                if (blank(data_get($answers, $question['id']))) {
                    $validator->errors()->add(
                        "answers.{$question['id']}",
                        'Responda todas as perguntas para continuar.',
                    );
                }
            }
        });
    }
}
```

- [ ] **Step 3: Sanity check both compile**

Run: `docker compose exec app php -l app/Http/Requests/UpdateFoodSharedRequest.php && docker compose exec app php -l app/Http/Requests/ClarifyAnalysisRequest.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/UpdateFoodSharedRequest.php app/Http/Requests/ClarifyAnalysisRequest.php
git commit -m "feat(analysis): add food-shared and analysis-clarify form requests"
```

---

## Task 10: Controller actions + routes

**Files:**
- Modify: `app/Http/Controllers/SessionController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AnalyzeSessionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\AnalysisStatus;
use App\Enums\ExtractionStatus;
use App\Jobs\AnalyzeBill;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('lets the owner toggle food_shared', function () {
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['food_shared' => true]);

    $this->actingAs($user)
        ->patch(route('sessions.food-shared', $session), ['food_shared' => false])
        ->assertRedirect();

    expect($session->fresh()->food_shared)->toBeFalse();
});

it('forbids a non-owner from toggling food_shared', function () {
    $session = Session::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('sessions.food-shared', $session), ['food_shared' => false])
        ->assertForbidden();
});

it('dispatches AnalyzeBill when receipt is completed and a participant exists', function () {
    Queue::fake();
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['status' => ExtractionStatus::Completed]);
    SessionParticipant::factory()->for($session, 'session')->create();

    $this->actingAs($user)
        ->post(route('sessions.analyze', $session))
        ->assertRedirect(route('sessions.show', $session));

    expect($session->fresh()->analysis_status)->toBe(AnalysisStatus::Processing);
    Queue::assertPushed(AnalyzeBill::class);
});

it('blocks analyze when receipt is not completed', function () {
    Queue::fake();
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['status' => ExtractionStatus::Pending]);
    SessionParticipant::factory()->for($session, 'session')->create();

    $this->actingAs($user)
        ->post(route('sessions.analyze', $session))
        ->assertForbidden();

    Queue::assertNotPushed(AnalyzeBill::class);
});

it('blocks analyze when there are no participants', function () {
    Queue::fake();
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create(['status' => ExtractionStatus::Completed]);

    $this->actingAs($user)
        ->post(route('sessions.analyze', $session))
        ->assertForbidden();

    Queue::assertNotPushed(AnalyzeBill::class);
});

it('records analysis clarification answers and re-dispatches', function () {
    Queue::fake();
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create([
        'status' => ExtractionStatus::Completed,
        'analysis_status' => AnalysisStatus::NeedsClarification,
        'analysis_clarifications' => [
            'round' => 0,
            'answered' => [],
            'pending' => [['id' => 'q1', 'prompt' => 'Quem bebeu a Heineken?', 'type' => 'text', 'options' => []]],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('sessions.analyze.clarify', $session), ['answers' => ['q1' => 'William']])
        ->assertRedirect(route('sessions.show', $session));

    $session->refresh();
    expect($session->analysis_status)->toBe(AnalysisStatus::Processing)
        ->and($session->analysis_clarifications['round'])->toBe(1)
        ->and($session->analysis_clarifications['answered'][0]['answer'])->toBe('William');

    Queue::assertPushed(AnalyzeBill::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=AnalyzeSessionTest`
Expected: FAIL — route `sessions.analyze` not defined.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, inside the `auth` group, after the existing `sessions.clarify` route:

```php
    Route::post('/sessions/{session}/analyze', [SessionController::class, 'analyze'])
        ->name('sessions.analyze');

    Route::post('/sessions/{session}/analyze/clarify', [SessionController::class, 'clarifyAnalysis'])
        ->name('sessions.analyze.clarify');

    Route::patch('/sessions/{session}/food-shared', [SessionController::class, 'updateFoodShared'])
        ->name('sessions.food-shared');
```

- [ ] **Step 4: Add controller imports**

In `app/Http/Controllers/SessionController.php`, add:

```php
use App\Enums\AnalysisStatus;
use App\Events\ReceiptAnalysisUpdated;
use App\Http\Requests\ClarifyAnalysisRequest;
use App\Http\Requests\UpdateFoodSharedRequest;
use App\Jobs\AnalyzeBill;
```

- [ ] **Step 5: Add the three controller methods**

Add to `SessionController` (after `clarify()`):

```php
    public function updateFoodShared(UpdateFoodSharedRequest $request, Session $session): RedirectResponse
    {
        if ($session->user_id !== auth()->id()) {
            abort(403);
        }

        if ($session->analysis_status === AnalysisStatus::Processing) {
            abort(403);
        }

        $session->update(['food_shared' => $request->validated('food_shared')]);

        return back();
    }

    public function analyze(Session $session): RedirectResponse
    {
        Log::info('[Controller][SessionController][analyze] Inicio da execusão.', [
            'session_id' => $session->id,
            'analysis_status' => $session->analysis_status->value,
        ]);

        if ($session->user_id !== auth()->id()) {
            abort(403);
        }

        if ($session->status !== ExtractionStatus::Completed) {
            abort(403);
        }

        if ($session->participants()->count() < 1) {
            abort(403);
        }

        $blocked = [AnalysisStatus::Processing, AnalysisStatus::NeedsClarification];
        if (in_array($session->analysis_status, $blocked, true)) {
            abort(403);
        }

        $session->update([
            'analysis_status' => AnalysisStatus::Processing,
            'analysis_result' => null,
            'analysis_clarifications' => null,
            'analysis_failure_reason' => null,
        ]);

        event(new ReceiptAnalysisUpdated($session->id, AnalysisStatus::Processing->value));

        AnalyzeBill::dispatch($session);

        Log::info('[Controller][SessionController][analyze] Job de análise despachado. Fim da execusão.', [
            'session_id' => $session->id,
        ]);

        return redirect()->route('sessions.show', $session);
    }

    public function clarifyAnalysis(ClarifyAnalysisRequest $request, Session $session): RedirectResponse
    {
        if ($session->user_id !== auth()->id()) {
            abort(403);
        }

        if ($session->analysis_status !== AnalysisStatus::NeedsClarification) {
            abort(403);
        }

        $clarifications = $session->analysis_clarifications ?? [];
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
            'analysis_status' => AnalysisStatus::Processing,
            'analysis_clarifications' => [
                'round' => ($clarifications['round'] ?? 0) + 1,
                'answered' => $answered,
                'pending' => [],
            ],
        ]);

        event(new ReceiptAnalysisUpdated($session->id, AnalysisStatus::Processing->value));

        AnalyzeBill::dispatch($session);

        return redirect()->route('sessions.show', $session);
    }
```

- [ ] **Step 6: Extend the `show()` payload**

In `SessionController::show()`, add these keys to the `'session' => [...]` array (after `'clarifications' => ...`):

```php
                'food_shared' => $session->food_shared,
                'analysis_status' => $session->analysis_status->value,
                'analysis_clarifications' => $session->analysis_clarifications,
                'analysis_result' => $session->analysis_result,
                'analysis_failure_reason' => $session->analysis_failure_reason,
```

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=AnalyzeSessionTest`
Expected: PASS (all cases).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/SessionController.php routes/web.php tests/Feature/AnalyzeSessionTest.php
git commit -m "feat(analysis): add analyze, clarifyAnalysis, updateFoodShared actions"
```

---

## Task 11: Public page — per-device breakdown + privacy

**Files:**
- Modify: `app/Http/Controllers/PublicSessionController.php`
- Test: `tests/Feature/PublicAnalysisVisibilityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\AnalysisStatus;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows only the current device participant breakdown on the public page', function () {
    $session = Session::factory()->for(User::factory())->create([
        'analysis_status' => AnalysisStatus::Completed,
    ]);

    $mine = SessionParticipant::factory()->for($session, 'session')->create([
        'name' => 'William',
        'submitter_token' => 'token-mine',
        'amount_due' => 205.32,
        'breakdown' => ['name' => 'William', 'total' => 205.32, 'items' => []],
    ]);
    SessionParticipant::factory()->for($session, 'session')->create([
        'name' => 'Camila',
        'submitter_token' => 'token-other',
        'amount_due' => 194.43,
        'breakdown' => ['name' => 'Camila', 'total' => 194.43, 'items' => []],
    ]);

    $response = $this->withCookie('tr_pid', 'token-mine')
        ->get(route('public.sessions.show', $session->public_token));

    $response->assertInertia(fn ($page) => $page
        ->where('session.analysis_status', 'completed')
        ->where('myBreakdown.total', 205.32)
        ->where('myBreakdown.name', 'William')
    );

    // Camila's amount must NOT appear anywhere in the payload.
    expect($response->getContent())->not->toContain('194.43');
});

it('exposes no breakdown to a device that did not submit', function () {
    $session = Session::factory()->for(User::factory())->create([
        'analysis_status' => AnalysisStatus::Completed,
    ]);
    SessionParticipant::factory()->for($session, 'session')->create([
        'submitter_token' => 'someone-else', 'amount_due' => 50.0,
        'breakdown' => ['total' => 50.0],
    ]);

    $this->get(route('public.sessions.show', $session->public_token))
        ->assertInertia(fn ($page) => $page->where('myBreakdown', null));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=PublicAnalysisVisibilityTest`
Expected: FAIL — `myBreakdown` prop missing.

- [ ] **Step 3: Update `PublicSessionController::show()`**

After `$existing = $this->existingParticipant($request, $session);`, compute the breakdown,
and add two keys to the Inertia payload. Add to the `'session' => [...]` array:

```php
                'analysis_status' => $session->analysis_status->value,
```

And add two top-level props next to `'alreadySubmitted'`:

```php
            'myBreakdown' => $existing?->breakdown,
            'myAmountDue' => $existing?->amount_due,
```

> The controller already loads `$existing` by the `tr_pid` cookie via `existingParticipant()`,
> so only that participant's `breakdown` enters the payload — other participants' amounts are
> never serialized.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter=PublicAnalysisVisibilityTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PublicSessionController.php tests/Feature/PublicAnalysisVisibilityTest.php
git commit -m "feat(analysis): expose per-device breakdown on the public page"
```

---

## Task 12: Owner UI — toggle, button, clarification, result panel

No JS test harness is configured; verify by reading the rendered page. Keep all copy PT-BR
and reuse the existing clarification markup pattern from the receipt flow.

**Files:**
- Modify: `resources/js/Pages/Sessions/Show.vue`

- [ ] **Step 1: Add analysis state + actions to the `<script setup>` block**

After the existing `clarifyForm` definition, add:

```js
const analyzeForm = useForm({});
const analysisClarifyForm = useForm({ answers: {} });
const foodSharedForm = useForm({ food_shared: props.session.food_shared });

const canAnalyze = computed(
    () =>
        props.session.status === 'completed' &&
        (props.session.participants?.length ?? 0) >= 1 &&
        !['processing', 'needs_clarification'].includes(props.session.analysis_status),
);

const allAnalysisAnswered = computed(() =>
    (props.session.analysis_clarifications?.pending ?? []).every(
        (q) => `${analysisClarifyForm.answers[q.id] ?? ''}`.trim() !== '',
    ),
);

function toggleFoodShared() {
    foodSharedForm
        .transform((data) => ({ food_shared: !props.session.food_shared }))
        .patch(route('sessions.food-shared', props.session.id), { preserveScroll: true });
}

function runAnalysis() {
    analyzeForm.post(route('sessions.analyze', props.session.id), { preserveScroll: true });
}

function submitAnalysisClarification() {
    analysisClarifyForm.post(route('sessions.analyze.clarify', props.session.id), {
        preserveScroll: true,
        onSuccess: () => analysisClarifyForm.reset(),
    });
}
```

> Ensure `computed` is imported from `vue` at the top of the file (the existing
> `allClarificationsAnswered` computed implies it already is; if not, add it to the
> `import { ... } from 'vue'` line).

- [ ] **Step 2: Listen for analysis broadcasts**

In the existing channel-subscription block (where `channel.listen('.extraction.updated', ...)`
is registered), add alongside it:

```js
    channel.listen('.analysis.updated', () => {
        router.reload({ only: ['session'] });
    });
```

- [ ] **Step 3: Add the analysis UI block to the template**

After the existing receipt/clarification section (inside the same card column, below the
items/summary), add:

```html
<div v-if="session.status === 'completed'" class="mt-8 border-t border-gray-200 pt-6">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-900">Análise da conta</h3>

        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
            <input
                type="checkbox"
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                :checked="session.food_shared"
                :disabled="session.analysis_status === 'processing'"
                @change="toggleFoodShared"
            />
            Comida compartilhada
        </label>
    </div>

    <p class="mt-1 text-xs text-gray-500">
        Comida não reivindicada é dividida igualmente; bebidas são sempre individuais.
    </p>

    <!-- trigger -->
    <div v-if="['pending', 'failed'].includes(session.analysis_status)" class="mt-4">
        <PrimaryButton :disabled="!canAnalyze || analyzeForm.processing" @click="runAnalysis">
            Analisar conta
        </PrimaryButton>
        <p v-if="session.analysis_failure_reason" class="mt-2 text-sm text-red-600">
            {{ session.analysis_failure_reason }}
        </p>
        <p v-if="(session.participants?.length ?? 0) < 1" class="mt-2 text-sm text-gray-500">
            Aguardando ao menos um participante enviar o que consumiu.
        </p>
    </div>

    <!-- processing -->
    <p v-else-if="session.analysis_status === 'processing'" class="mt-4 text-sm text-gray-600">
        Analisando a conta…
    </p>

    <!-- needs clarification -->
    <div v-else-if="session.analysis_status === 'needs_clarification'" class="mt-4 space-y-4">
        <p class="text-sm text-gray-700">Precisamos de algumas respostas para fechar a conta:</p>
        <div
            v-for="question in (session.analysis_clarifications?.pending ?? [])"
            :key="question.id"
            class="rounded-lg border border-gray-200 p-3"
        >
            <p class="text-sm font-medium text-gray-800">{{ question.prompt }}</p>
            <div v-if="question.type === 'choice'" class="mt-2 flex flex-wrap gap-2">
                <button
                    v-for="option in question.options"
                    :key="option"
                    type="button"
                    class="rounded-full border px-3 py-1 text-sm"
                    :class="analysisClarifyForm.answers[question.id] === option
                        ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                        : 'border-gray-300 text-gray-700'"
                    @click="analysisClarifyForm.answers[question.id] = option"
                >
                    {{ option }}
                </button>
            </div>
            <input
                v-else
                v-model="analysisClarifyForm.answers[question.id]"
                type="text"
                class="mt-2 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                placeholder="Sua resposta"
            />
        </div>
        <PrimaryButton
            :disabled="analysisClarifyForm.processing || !allAnalysisAnswered"
            @click="submitAnalysisClarification"
        >
            Enviar respostas
        </PrimaryButton>
    </div>

    <!-- completed -->
    <div v-else-if="session.analysis_status === 'completed'" class="mt-4 space-y-3">
        <div
            v-for="person in (session.analysis_result?.participants ?? [])"
            :key="person.participant_id"
            class="rounded-lg border border-gray-200 p-4"
        >
            <div class="flex items-center justify-between">
                <span class="font-semibold text-gray-900">{{ person.name }}</span>
                <span class="font-semibold text-gray-900">{{ formatBrl(person.total) }}</span>
            </div>
            <ul class="mt-2 space-y-1 text-sm text-gray-600">
                <li v-for="(item, idx) in person.items" :key="idx">
                    {{ item.quantity }}x {{ item.name }} — {{ formatBrl(item.total_price) }}
                </li>
                <li v-if="person.shared_food_share > 0">
                    Parte da comida compartilhada — {{ formatBrl(person.shared_food_share) }}
                </li>
            </ul>
            <div class="mt-2 text-xs text-gray-500">
                Subtotal {{ formatBrl(person.subtotal) }} · Gorjeta {{ formatBrl(person.tip) }}
            </div>
        </div>
        <div class="flex items-center justify-between border-t border-gray-200 pt-3 font-semibold">
            <span>Total</span>
            <span>{{ formatBrl(grandTotal) }}</span>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Add the `formatBrl` helper and `grandTotal` computed**

If a currency formatter already exists in this file (the spec references one added in a
prior commit — check the `<script setup>` for an existing `formatBrl`/`formatCurrency`), reuse
it and skip the helper. Otherwise add:

```js
function formatBrl(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
        Number(value ?? 0),
    );
}

const grandTotal = computed(() =>
    (props.session.analysis_result?.participants ?? []).reduce(
        (sum, p) => sum + Number(p.total ?? 0),
        0,
    ),
);
```

- [ ] **Step 5: Build assets and verify no errors**

Run: `docker compose exec app npm run build`
Expected: build completes with no errors.

- [ ] **Step 6: Manual verification**

Bring the stack up (`docker compose up -d`), open a session with extraction `completed` and at
least one participant, confirm: the toggle persists on click; "Analisar conta" dispatches;
after the worker finishes, per-person cards render and the grand total equals the bill total.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Sessions/Show.vue
git commit -m "feat(analysis): add owner analysis UI (toggle, button, clarify, result)"
```

---

## Task 13: Public UI — "Seu valor a pagar"

**Files:**
- Modify: `resources/js/Pages/Public/Session.vue`

- [ ] **Step 1: Accept the new props**

In the `defineProps({...})` (or `defineProps([...])`) call, add `myBreakdown` and `myAmountDue`
(and `session.analysis_status` is already inside the `session` prop object). If props are typed
as an array of strings, add `'myBreakdown', 'myAmountDue'`.

- [ ] **Step 2: Add the per-person view to the template**

Place this above or below the existing "Enviado, obrigado" confirmation block:

```html
<div
    v-if="session.analysis_status === 'completed' && myBreakdown"
    class="mt-6 rounded-lg border border-gray-200 bg-white p-4"
>
    <h2 class="text-lg font-semibold text-gray-900">Seu valor a pagar</h2>
    <ul class="mt-3 space-y-1 text-sm text-gray-600">
        <li v-for="(item, idx) in (myBreakdown.items ?? [])" :key="idx">
            {{ item.quantity }}x {{ item.name }} — {{ formatBrl(item.total_price) }}
        </li>
        <li v-if="(myBreakdown.shared_food_share ?? 0) > 0">
            Parte da comida compartilhada — {{ formatBrl(myBreakdown.shared_food_share) }}
        </li>
    </ul>
    <div class="mt-2 text-xs text-gray-500">
        Subtotal {{ formatBrl(myBreakdown.subtotal) }} · Gorjeta {{ formatBrl(myBreakdown.tip) }}
    </div>
    <div class="mt-3 flex items-center justify-between border-t border-gray-200 pt-3 text-lg font-semibold">
        <span>Total</span>
        <span>{{ formatBrl(myBreakdown.total) }}</span>
    </div>
</div>

<p
    v-else-if="session.analysis_status === 'completed' && !myBreakdown"
    class="mt-6 text-sm text-gray-500"
>
    A conta foi finalizada. Você não enviou o que consumiu neste dispositivo.
</p>
```

- [ ] **Step 3: Add the `formatBrl` helper (if absent) and live reload listener**

If the page has no currency formatter, add inside `<script setup>`:

```js
function formatBrl(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
        Number(value ?? 0),
    );
}
```

Add an Echo listener on the public channel so the page refreshes when analysis completes
(inside `onMounted`, mirroring the owner page's subscription pattern):

```js
import { router } from '@inertiajs/vue3';
import { onMounted, onBeforeUnmount } from 'vue';

let publicChannel = null;
const publicChannelName = `bill-session.${props.session.token}.public`;

onMounted(() => {
    if (!window.Echo || publicChannel) {
        return;
    }
    publicChannel = window.Echo.channel(publicChannelName);
    publicChannel.listen('.analysis.completed', () => {
        router.reload();
    });
});

onBeforeUnmount(() => {
    if (publicChannel) {
        window.Echo.leave(publicChannelName);
        publicChannel = null;
    }
});
```

> `props.session.token` is already sent by `PublicSessionController::show()`. If the page
> already imports `onMounted`/`router`, merge into the existing imports instead of duplicating.

- [ ] **Step 4: Build assets**

Run: `docker compose exec app npm run build`
Expected: build completes with no errors.

- [ ] **Step 5: Manual verification**

From two different browsers/devices (distinct `tr_pid` cookies), submit as two participants,
run the analysis as the owner, and confirm each device sees only its own "Seu valor a pagar"
and the total updates live without a manual refresh.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Public/Session.vue
git commit -m "feat(analysis): show per-device amount on the public page"
```

---

## Task 14: Factories + full suite + Pint

**Files:**
- Modify: `database/factories/SessionFactory.php`, `database/factories/SessionParticipantFactory.php` (only if defaults are needed)

- [ ] **Step 1: Ensure factory defaults are analysis-safe**

Open `database/factories/SessionFactory.php`. The migration default (`food_shared = true`,
`analysis_status = 'pending'`) covers creation, so no change is required unless a test needs an
explicit default. Leave factories untouched if all Task 2–11 tests pass. (This step is a
verification checkpoint, not a forced edit.)

- [ ] **Step 2: Run the full test suite**

Run: `docker compose exec app composer run test`
Expected: all tests PASS (existing + new).

- [ ] **Step 3: Run Pint**

Run: `docker compose exec app ./vendor/bin/pint`
Expected: clean (files reformatted if needed).

- [ ] **Step 4: Commit any Pint changes**

```bash
git add -A
git commit -m "style(analysis): apply pint formatting"
```

---

## Self-Review notes (for the implementer)

- **Spec coverage:** toggle (Task 2/10/12), button gated on completed+participants (Task 10/12),
  audio+text transcription (Task 6), claim-matching + PHP reconciliation with "ask until it
  balances" (Tasks 4/6/8), owner-only clarification (Task 9/10/12), owner sees everyone /
  public sees only own (Tasks 11/12/13), live updates (Tasks 7/12/13).
- **`forceFinal` always closes:** enforced in `BillReconciler` (Task 4) and exercised by the job
  at `round >= MAX_ROUNDS` (Task 8).
- **No real network in tests:** the suite binds `FakeBillSplitter` (Task 5, step 6); only
  `PrismBillSplitter` touches Prism and is verified manually.
- **Money correctness:** all arithmetic lives in `BillReconciler` and is unit-tested against the
  worked example; the LLM never computes money.
