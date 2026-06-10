# Bill Analysis (Split Calculation) — Design

**Date:** 2026-06-10
**Status:** Approved (pending implementation plan)

## Summary

After a session's receipt has been read (extraction `Completed`) and at least one
participant has submitted what they consumed via the public link (audio and/or
text), the session owner can run a **bill analysis** that computes how much each
participant must pay — their consumed items, an equal share of any shared food,
proportional tip, and a per-person total that reconciles to the bill total.

The owner controls a **"comida compartilhada" (shared food) toggle**. The
analysis mirrors the existing receipt-extraction pipeline: a queued AI job that
may pause to ask the owner clarifying questions over a bounded number of rounds,
persists state, and broadcasts updates live.

The owner (logged in) sees everyone's breakdown. A public participant sees only
their own amount and items — never anyone else's.

## Goals

- Owner can toggle whether food is shared, and run "Analisar conta" once ≥ 1
  participant has submitted and the receipt extraction is `Completed`.
- AI matches each participant's natural-language claims (from transcript/text) to
  receipt line items; PHP computes the money and reconciles to the total.
- When information is missing or the per-person sum does not equal the bill total,
  the analysis asks the owner clarifying questions until it reconciles.
- Owner view shows the full breakdown for all participants; public view shows the
  current device's participant breakdown only.
- Live updates on both the owner page and the public page.

## Non-goals

- No per-participant clarification answering on the public link (owner answers all
  questions). 
- No support for diners who did not submit: the submitted participants are assumed
  to be the entire bill. Unaccounted consumption surfaces as a clarifying question.
- No manual drag-and-drop item assignment UI in this iteration (AI + clarification
  only).

## Key decisions (from brainstorming)

1. **Toggle scope:** the toggle controls **food only**. Drinks are *always*
   individually claimed; any unclaimed drink raises a clarifying question. Food:
   toggle ON → unclaimed food is split equally among submitted participants;
   toggle OFF → unclaimed food raises a clarifying question.
2. **Clarification audience:** **owner only**, on the logged-in session page,
   mirroring the existing receipt-clarify flow.
3. **Split base:** the participants who submitted are the **entire bill**. Shared
   food divides equally among them; the per-person sum must reconcile to
   `session.total` or the analysis asks the owner.
4. **Architecture:** a **separate analysis phase** parallel to extraction (its own
   status/clarifications/result columns), reusing the proven extraction pattern.
   The **LLM does only fuzzy claim-matching and question generation**; **PHP does
   all arithmetic and the reconciliation check.**

## Worked example (sanity check)

Bill: 1× Parmegiana (R$119,90), 3× Bife a Cavalo (R$50,00 = R$150,00),
3× Heineken (R$9,90 = R$29,70), 2× Moscow Mule (R$31,90 = R$63,80); 10% tip.
Food shared = ON. Submitters: William ("1 Moscow Mule, 2 Heineken"),
Camila ("1 Heineken, 1 Moscow Mule").

- Drinks claimed: William 1 Moscow + 2 Heineken; Camila 1 Moscow + 1 Heineken →
  all 2 Moscow + 3 Heineken accounted, no leftover drinks.
- Food leftover (nobody claimed): Parmegiana + 3 Bife = R$269,90 shared equally →
  R$134,95 each.
- William consumed: R$31,90 + 2×R$9,90 + R$134,95 = R$186,65; tip 10% = R$18,665;
  total ≈ R$205,32.
- Camila consumed: R$9,90 + R$31,90 + R$134,95 = R$176,75; tip 10% = R$17,675;
  total ≈ R$194,43.
- Σ totals ≈ R$399,74 ≈ bill total (subtotal R$363,40 + 10% = R$399,74). Reconciles.

## Data model

### New enum: `App\Enums\AnalysisStatus`

`pending`, `processing`, `completed`, `needs_clarification`, `failed` — independent
of `ExtractionStatus` so the two phases never collide on a single flag.

### Migration 1 — `bill_sessions` (new columns)

- `food_shared` boolean, default `true` — the toggle.
- `analysis_status` string, default `pending`.
- `analysis_clarifications` JSON nullable — `{round, answered, pending}`, identical
  shape to the existing `clarifications`.
- `analysis_result` JSON nullable — full computed breakdown for all participants;
  source of truth for the owner view and reconciliation.
- `analysis_failure_reason` text nullable.
- `analyzed_at` timestamp nullable.

### Migration 2 — `session_participants` (new columns)

- `transcript` text nullable — cached audio transcription (avoids re-transcribing
  on re-analysis).
- `amount_due` decimal(10,2) nullable — denormalized per-person total for cheap
  public lookup.
- `breakdown` JSON nullable — that participant's consumed items + shared-food share
  + tip + total.

Models (`Session`, `SessionParticipant`) get the new `$fillable` and `casts`
entries: `food_shared` → boolean, `analysis_status` → `AnalysisStatus`,
`analysis_result`/`analysis_clarifications`/`breakdown` → array, `analyzed_at` →
datetime, `amount_due` → `decimal:2`.

All new migrations created via `php artisan make:migration`; no existing migration
is edited.

## Service pipeline

### `App\Services\Bill\BillSplitter` (interface)

```php
public function split(
    Session $session,
    array $participants,   // submitted participants with name + transcript/text
    bool $foodShared,
    array $answered,       // prior Q&A fed back
    bool $forceFinal,      // final round: must return complete
): SplitResult;
```

Implementations: `FakeBillSplitter` (deterministic, no network — for tests) and
`PrismBillSplitter`. Bound in `AppServiceProvider` exactly like `ReceiptExtractor`.

### `App\Services\Bill\SplitResult` (value object)

Mirrors `ExtractionResult`: `complete(array $allocations)` or
`requestInput(array $questions, array $raw)`, with `needsInput()`. `allocations`
is the per-participant computed breakdown; `questions` use the same
`{id, prompt, type, options}` shape the existing clarify UI renders.

### `PrismBillSplitter` — two stages

**Stage 1 — Transcription.** For each participant with `audio_path` and no cached
`transcript`, call
`Prism::audio()->using(Provider::OpenAI, $integration->audio_model)->withInput(Audio::fromLocalPath($absolutePath))->asText()`
and persist `transcript`. Text-only participants skip this stage.

**Stage 2 — Claim-matching (structured LLM call).** Inputs: receipt items, each
participant's name and combined transcript/text, the `food_shared` flag, and any
prior `answered` Q&A. Structured schema returns **either**:

- `status: needs_input` with `questions[]` (`{id, prompt, type, options}`), or
- `status: complete` with `claims[]`: per participant, `{item_name, quantity}[]`
  for items that person personally consumed (all drinks; explicitly-claimed food).

The LLM performs only matching + question generation — **no arithmetic**.

### Deterministic reconciliation (PHP, after LLM returns `complete`)

1. Subtract every claimed unit from receipt quantities → a **leftover pool**.
2. **Leftover drinks** (always) and **leftover food when `food_shared` is false**:
   if any remain, raise a clarifying question (do not guess).
3. **Leftover food when `food_shared` is true**: split its value equally across all
   submitted participants.
4. Per participant: `consumed = own claimed items + equal share of shared food`;
   `tip = consumed × service_charge_percentage`; `total = consumed + tip`.
5. **Reconcile:** assert `Σ participant totals == session.total` within R$0.01. If
   it does not balance (over-claim, missing claim, unassignable leftover), raise a
   clarifying question instead of returning. This is the "ask until it balances"
   guarantee — enforced by math, not the model.

On the **final forced round** (`forceFinal`), any still-unassignable leftover is
distributed via the shared-food rule across all participants so the analysis always
closes, matching extraction's `forceFinal` behavior.

Tip percentage is taken from the already-extracted `service_charge_percentage`.

### `App\Jobs\AnalyzeBill`

Mirrors `ExtractReceiptItems`: `MAX_ROUNDS = 2`, `tries = 3`, raised `timeout`
(audio transcription + LLM). Reads `analysis_clarifications` round/answered, calls
the splitter with `forceFinal = round >= MAX_ROUNDS`.

- On `needsInput` (and not forced): `analysis_status = needs_clarification`, store
  pending questions in `analysis_clarifications`, broadcast.
- On complete: write `analysis_result`, set each participant's `amount_due` and
  `breakdown`, `analysis_status = completed`, `analyzed_at = now()`, clear
  clarifications/reason, broadcast.
- `failed()`: `analysis_status = failed` + `analysis_failure_reason`, broadcast.

## Routes & controllers

### Routes (`routes/web.php`, inside the `auth` group)

```php
Route::post('/sessions/{session}/analyze', [SessionController::class, 'analyze'])
    ->name('sessions.analyze');
Route::post('/sessions/{session}/analyze/clarify', [SessionController::class, 'clarifyAnalysis'])
    ->name('sessions.analyze.clarify');
Route::patch('/sessions/{session}/food-shared', [SessionController::class, 'updateFoodShared'])
    ->name('sessions.food-shared');
```

### `SessionController` actions

Each owner-gated (`$session->user_id !== auth()->id()` → 403) and logged in the
established `[Controller][SessionController][...]` style.

- **`updateFoodShared`** — `UpdateFoodSharedRequest` validates a boolean; persists
  `food_shared`; disallowed while `analysis_status === processing`. Returns back.
- **`analyze`** — preconditions: receipt `status === Completed` and
  `participants` count ≥ 1. Blocks re-entry when `analysis_status` is
  `processing`/`needs_clarification` (same guard-list pattern as `extract`). Sets
  `analysis_status = processing`, clears prior result/clarifications/reason,
  broadcasts, dispatches `AnalyzeBill`.
- **`clarifyAnalysis`** — `ClarifyAnalysisRequest` (same `answers` validation as
  `ClarifyExtractionRequest`). Mirrors the existing `clarify`, against
  `analysis_clarifications`, gated on `analysis_status === NeedsClarification`.
  Appends answered Q&A, bumps round, re-dispatches `AnalyzeBill`.

### Form Requests

- `UpdateFoodSharedRequest` — validates `food_shared` boolean.
- `ClarifyAnalysisRequest` — same shape as `ClarifyExtractionRequest`.

## Broadcasting

- **`App\Events\ReceiptAnalysisUpdated`** on the existing private channel
  `bill-session.{id}` (already owner-authorized), carrying `analysis_status` +
  optional reason. `Sessions/Show.vue` listens for it alongside
  `ReceiptExtractionUpdated` and reloads via Inertia partial.
- **Public live update:** broadcast a lightweight `analysis-completed` signal on a
  **public** channel `bill-session.{id}.public`. It carries no per-person data — it
  only tells the public page to reload, which then fetches the current device's
  breakdown server-side.

## UI

### Owner — `Sessions/Show.vue` (analysis block shown once receipt `Completed`)

- **Shared-food toggle** — labeled switch bound to `food_shared`, PATCHes
  `sessions.food-shared` on change; disabled while analysis `processing`. Helper
  text: *"Comida não reivindicada é dividida igualmente; bebidas são sempre
  individuais."*
- **"Analisar conta" button** — visible once `participants.length ≥ 1`; disabled
  (with reason) when receipt not completed or no participants. Triggers
  `sessions.analyze`; shows "Analisando…" while `processing`.
- **Clarification panel** — when `needs_clarification`, render
  `analysis_clarifications.pending` reusing the existing receipt-clarify question UI
  (choice → radios, text → input), POSTing to `sessions.analyze.clarify`. Extract
  the question component for sharing if not already shared.
- **Result panel** — when `completed`, render `analysis_result`: one card per
  participant (consumed items qty × name, shared-food share, subtotal, tip with %,
  **total a pagar**) plus a grand-total line that visibly equals the bill total
  (reconciliation proof). Failure shows `analysis_failure_reason` with retry.

### Public — `Public/Session.vue` (privacy enforced server-side)

`PublicSessionController::show` looks up the participant by the `tr_pid` cookie and
serializes **only** that participant's `amount_due`/`breakdown`. Other people's
numbers never enter the Inertia payload.

- Before analysis completes: current "Enviado, obrigado" state unchanged.
- When `analysis_status === completed` and this device has a participant row: show
  **"Seu valor a pagar"** — their consumed items, shared-food share, tip, total —
  and nothing about anyone else.
- A visitor who did not submit sees a neutral "a conta ainda não foi finalizada /
  você não participou" message.
- Live: listens on `bill-session.{id}.public` for `analysis-completed` and reloads.

All copy PT-BR; reuse Breeze primitives and the existing summary/markdown rendering
style.

## Testing (Pest, SQLite in-memory, `QUEUE_CONNECTION=sync`)

- **Splitter unit/feature tests via `FakeBillSplitter`** and the deterministic PHP
  reconciliation: the worked example reconciles; leftover drink → question;
  leftover food with toggle OFF → question; toggle ON → equal split; over-claim →
  question; `forceFinal` always closes.
- **Controller tests:** owner-only gates (403 for non-owner) on `analyze`,
  `clarifyAnalysis`, `updateFoodShared`; preconditions (receipt not completed / no
  participants block `analyze`); re-entry guards; clarify appends answers and bumps
  round.
- **Public privacy test:** public payload includes only the cookie-identified
  participant's breakdown; a different/absent cookie sees no amounts.
- **Broadcast tests:** `ReceiptAnalysisUpdated` fired on the private channel;
  `analysis-completed` on the public channel.
- **Pint** clean before completion.

## Files (anticipated)

**New:** `app/Enums/AnalysisStatus.php`,
`app/Services/Bill/BillSplitter.php`, `SplitResult.php`, `PrismBillSplitter.php`,
`FakeBillSplitter.php`, `app/Jobs/AnalyzeBill.php`,
`app/Events/ReceiptAnalysisUpdated.php`,
`app/Http/Requests/UpdateFoodSharedRequest.php`, `ClarifyAnalysisRequest.php`,
two migrations, factory updates, tests.

**Modified:** `app/Models/Session.php`, `SessionParticipant.php`,
`app/Http/Controllers/SessionController.php`,
`app/Http/Controllers/PublicSessionController.php`,
`app/Providers/AppServiceProvider.php`, `routes/web.php`, `routes/channels.php`,
`resources/js/Pages/Sessions/Show.vue`, `resources/js/Pages/Public/Session.vue`,
and a shared clarification-question Vue component.
