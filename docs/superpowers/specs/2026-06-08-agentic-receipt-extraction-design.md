# Agentic Receipt Extraction with Clarification Loop

**Date:** 2026-06-08
**Status:** Approved design — pending implementation plan

## Problem

The "Ler conta com IA" button reads a receipt photo and extracts line items
and totals in a single one-shot pass. It cannot:

- Classify each item as **Comida** or **Bebida**.
- Record the tip as a **percentage** (only the absolute `service_charge` is stored).
- Handle ambiguity — today the job either succeeds or fails. When the model is
  unsure ("is this item food or drink?", "I can't read this price") it has no
  way to ask the user; it would silently guess or fail.
- Present a human-readable **summary** grouped by category with totals.

## Goals

1. Extract per-item **category** (food/drink), quantity, unit price, line total.
2. Record tip **percentage** and absolute value, plus subtotal (sem gorjeta) and
   total (com gorjeta).
3. When the model is genuinely unsure, **pause and ask the user** (true agent
   loop), incorporate the answers, and re-run until it can finish.
4. Render a deterministic **markdown summary** grouped by Comida/Bebida + totals,
   matching the user's template.

## Non-Goals

- A free-form chat thread. Clarification is a bounded, structured Q&A loop.
- AI-generated prose summary. The summary is rendered deterministically from data.
- A third item category. Only food/drink; ambiguous items trigger a question.
- User editing of extracted items inline (future work).

## Decisions (locked during brainstorming)

- **Clarification flow:** true agent loop — a `needs_clarification` state; the
  model's questions surface in the UI; the user answers; the model re-runs.
- **Applying answers:** re-call the vision model with the image + prior Q&A. The
  model may ask again, up to a round cap.
- **Summary:** rendered deterministically from stored data (no AI, no drift).
- **Max rounds:** 2, then a forced final pass that must return a complete result.
- **Categories:** `food` | `drink` only. Items that are neither (couvert, taxas)
  trigger a clarification question rather than a third category.
- **Summary location:** built server-side (unit-testable in Pest; no JS test
  runner is configured).

## Data Model Changes

Three migrations (generated via `php artisan make:migration`, never hand-edited
after running):

1. **`session_items`** — add `category` string column (`food` | `drink`),
   nullable at the column level but always set when a session reaches
   `completed` (items are only persisted on completion).
2. **`bill_sessions`** — add `service_charge_percentage` (`decimal(5,2)`,
   nullable). Existing `service_charge` (absolute), `subtotal` (sem gorjeta) and
   `total` (com gorjeta) are unchanged.
3. **`bill_sessions`** — add `clarifications` (`json`, nullable) holding the
   agent conversation between rounds:

   ```json
   {
     "round": 1,
     "answered": [{ "question": "...", "answer": "..." }],
     "pending":  [{ "id": "q1", "prompt": "...", "type": "choice", "options": ["Comida", "Bebida"] }]
   }
   ```

### Enums

- New `App\Enums\ItemCategory: string { Food = 'food'; Drink = 'drink'; }` with a
  `label(): string` returning PT-BR `Comida` / `Bebida`.
- `App\Enums\ExtractionStatus` — add `NeedsClarification = 'needs_clarification'`.

### Model casts

- `SessionItem`: cast `category` to `ItemCategory`.
- `Session`: cast `service_charge_percentage` to `decimal:2`; `clarifications`
  to `array`. Add `category`, `service_charge_percentage`, `clarifications` to
  `$fillable` where appropriate.

## Extractor: branching agent

`ReceiptExtractor` contract changes:

```php
public function extract(
    string $absoluteImagePath,
    array $answered = [],      // [['question' => ..., 'answer' => ...], ...]
    bool $forceFinal = false,  // final round: model must return a complete result
): ExtractionResult;
```

The Prism structured-output schema gains a **discriminator**:

- `status`: enum `complete` | `needs_input`.
- `questions[]` (when `needs_input`): each `{ id, prompt, type: choice|text, options[] }`.
- `items[]` (when `complete`): each now includes `category` (enum `food`|`drink`),
  plus existing `name`, `quantity`, `unit_price`, `total_price`.
- `subtotal`, `service_charge`, `service_charge_percentage`, `total` (when complete).

Prompt rules:

- Do **not** guess. If a category is unclear or a value is unreadable, return
  `needs_input` with precise, minimal questions.
- When `answered` is non-empty, append the prior Q&A as additional context so the
  model resolves what it previously asked.
- When `forceFinal` is true, `needs_input` is forbidden — the model must make its
  best effort and return `complete`.
- `service_charge_percentage`: extract the stated percentage when visible on the
  receipt; if absent but a charge exists, the job derives it
  (`round(service_charge / subtotal * 100)` when `subtotal > 0`).

### `ExtractionResult`

Becomes a discriminated result object:

- `status: 'complete' | 'needs_input'`
- `questions: array` (populated when `needs_input`)
- `items: array` (each with `category`), `subtotal`, `serviceCharge`,
  `serviceChargePercentage`, `total` (populated when `complete`)
- `raw: array`

### `FakeReceiptExtractor`

Updated to the new contract. Returns a `complete` result with categories and a
percentage by default. The clarification-loop test binds an ad-hoc extractor (or
a scripted fake) that returns `needs_input` on round 1 and `complete` on round 2.

## Job & Flow

`ExtractReceiptItems@handle`:

1. Read `answered` from `session.clarifications`. Determine `forceFinal` =
   `round >= MAX_ROUNDS` (MAX_ROUNDS = 2).
2. Call `extract($path, $answered, $forceFinal)`.
3. If `status === needs_input` **and** under the cap:
   - Store `pending` questions into `clarifications`, status → `NeedsClarification`.
   - Broadcast `ReceiptExtractionUpdated`. Persist **no** items.
4. Else (`complete`, or cap reached):
   - Replace items, persisting `category` per item.
   - Persist `subtotal`, `service_charge`, `service_charge_percentage`, `total`,
     `raw_extraction`, `processed_at`; status → `Completed`; clear `failure_reason`.
   - Broadcast.

`failed()` is unchanged (status → `Failed`, reason stored, broadcast).

### Routes & Controller

- `POST /sessions/{session}/extract` (existing) — entry point. Allowed only from
  `pending` / `failed`; starting fresh resets `clarifications`.
- `POST /sessions/{session}/clarify` (**new**) → `SessionController@clarify`:
  - Guard: only when status === `needs_clarification` and `$session->user_id ===
    auth()->id()`.
  - Validate answers against the `pending` questions (a Form Request).
  - Append `{question, answer}` pairs to `clarifications.answered`, bump `round`.
  - status → `Processing`, broadcast, re-dispatch `ExtractReceiptItems`.

`show()` passes additional props: per-item `category`, `service_charge_percentage`,
pending `clarifications.pending` (when applicable), and `summary_markdown`.

## Summary Builder (server-side)

`App\Services\Receipt\ReceiptSummary` — builds the markdown from a `Session`'s
items + totals, matching the user's template:

```markdown
# Consumidos

## Comida
- 1 x Parmegiana (R$ 119,90) - R$ 119,90

## Bebida
- 3 x Heineken (R$ 9,90) - R$ 29,70

# Valores totais
- Sub-total: R$ 363,40
- Gorjeta (10%): R$ 36,34
- Total: R$ 399,74
```

Rules: omit an empty category section; format currency as PT-BR BRL; show the tip
line only when a charge exists, with the percentage when known. Passed to the
page as `summary_markdown`. Stays fresh because the page reloads on each
broadcast.

## UI (Show.vue)

- **needs_clarification** block: renders `clarifications.pending` as a small form.
  `choice` → option buttons (e.g. Comida/Bebida); `text` → input. Submits to
  `sessions.clarify` via `useForm`, with a processing state.
- **completed** block: items grouped into **Comida** / **Bebida** tables
  (mirroring the summary), totals row shows **Gorjeta (X%)**.
- **Resumo** block: renders `summary_markdown` with a "Copiar resumo" button that
  copies the raw markdown to the clipboard.

All copy in PT-BR. Reuse Breeze primitives (`PrimaryButton`, `TextInput`,
`InputLabel`, `InputError`) before adding components.

## Testing (Pest)

- `ItemCategory` enum cases and `label()`.
- `SessionItem` casts `category` to `ItemCategory`.
- Fake extractor returns a complete result with categories + percentage.
- Job persists item categories + `service_charge_percentage`.
- Job: extractor returns `needs_input` → status `needs_clarification`, questions
  stored in `clarifications`, no items persisted, event broadcast.
- `clarify` endpoint: appends answers, bumps round, re-dispatches; guarded to
  `needs_clarification`; rejects unauthorized users.
- Round cap: on the final round `forceFinal` is passed and a complete result is
  persisted even if the (scripted) extractor would otherwise ask again.
- `ReceiptSummary` builder: grouping, empty-section omission, tip line/percentage,
  BRL formatting.

Tests run against SQLite in-memory with `RefreshDatabase`; `QUEUE_CONNECTION=sync`.

## Risks / Open Points

- The Prism `use_tool_calling` path must support the discriminated schema; verify
  the model reliably honors `forceFinal`. The cap guarantees termination
  regardless.
- Deriving the tip percentage when only the absolute charge is present can be
  off for odd values; acceptable since the receipt's stated percentage wins.
