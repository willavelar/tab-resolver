# Design: "Outros" category + per-session sharing toggle

**Date:** 2026-06-11
**Status:** Approved

## Problem

Today receipts classify every item as `food` or `drink`. Some line items are
neither consumable food nor drink — e.g. parking ("estacionamento"), couvert, or
service taxes. They need their own category, and the bill analysis must let the
owner decide whether such items are split equally among everyone or attributed to
specific people — exactly like the existing "comida compartilhada" toggle.

## Decisions

- Default sharing state for "outros": **not shared** (`others_shared = false`).
  Unclaimed "outros" items always prompt "who consumed this?" unless the owner
  flips the toggle to shared.
- The toggle appears **only on the owner's Show page** (`Sessions/Show.vue`),
  mirroring `food_shared`, which is also absent from the public page.
- The toggle is shown **only when the bill contains at least one "outros" item**.

## How the existing "shared" mechanic works

`BillReconciler::reconcile` matches each participant's stated consumption against
the catalog. After matching, any *leftover* (unclaimed) item is handled by the
`food_shared` policy: if it is food and `food_shared` is true, its value joins
`sharedFoodValue` and is split equally; otherwise the reconciler returns a
clarification question. Drinks are never shared. `food_shared` is a boolean on
`Session`, toggled via `PATCH /sessions/{session}/food-shared`, and flows
`AnalyzeBill → BillSplitter → BillReconciler`.

Adding "outros" is a second instance of this same leftover policy switch.

## Changes

### 1. Category enum — `app/Enums/ItemCategory.php`
Add `case Other = 'other';` with label `'Outros'`. Single source of truth.

### 2. AI extraction — `app/Services/Receipt/PrismReceiptExtractor.php`
- Category `EnumSchema` options become `['food', 'drink', 'other']`.
- Description + prompt updated: "other para itens não consumíveis, como
  estacionamento, couvert ou taxas".
- The two `in_array($item['category'], ['food','drink'])` fallback guards accept
  `'other'` too.

### 3. The `others_shared` flag
- **Migration**: `boolean('others_shared')->default(false)->after('food_shared')`
  on `bill_sessions`.
- **`Session` model**: add `'others_shared'` to `$fillable`; cast to `'boolean'`.

### 4. Reconciler — `app/Services/Bill/BillReconciler::reconcile`
- Add `bool $othersShared` parameter.
- Leftover decision generalizes to:
  `shared = ($isFood && $foodShared) || ($isOther && $othersShared)`.
- Shared value joins the equally-split pool (internal variable renamed
  `sharedFoodValue → sharedValue`). The allocation field `shared_food_share`
  **keeps its name** to avoid breaking already-persisted `breakdown` JSON and the
  Vue that reads it.
- The leftover-question `$kind` label resolves via
  `ItemCategory::from($entry['category'])->label()` so "outros" reads correctly.

### 5. Plumbing
- `BillSplitter` interface, `PrismBillSplitter`, `FakeBillSplitter`: add
  `bool $othersShared` after `$foodShared`.
- `AnalyzeBill::handle`: pass `(bool) $this->session->others_shared`.

### 6. Controller + route
- New `UpdateOthersSharedRequest` (mirror of `UpdateFoodSharedRequest`).
- New `SessionController::updateOthersShared` (mirror of `updateFoodShared`, same
  owner + not-processing 403 guards).
- Route `PATCH /sessions/{session}/others-shared` → `sessions.others-shared`.
- Add `'others_shared' => $session->others_shared` to the `show()` props.

### 7. UI — `Sessions/Show.vue` only
- `hasOtherItems = computed(() => items.some(i => i.category === 'other'))`.
- A second toggle pair ("Outros compartilhados" / "Outros não compartilhados"),
  `v-if="hasOtherItems"`, below the food toggle, wired to `setOthersShared(value)`
  PATCHing the new route.
- Help text extended to mention that "outros" items follow the same rule.

## Testing (Pest)

- **Reconciler**: unclaimed "other" item with `othersShared=false` → clarification
  question; with `othersShared=true` → split equally into `shared_food_share`.
- **Feature**: `PATCH /sessions/{id}/others-shared` updates the flag and enforces
  the owner + not-processing guards.
- **Extraction**: an item categorized `other` survives validation and persistence.

## Out of scope

- No change to the public participant page.
- No change to the `food_shared` toggle behavior or its always-visible display.
- No renaming of the persisted `shared_food_share` result field.
