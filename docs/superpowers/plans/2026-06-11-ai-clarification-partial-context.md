# Mostrar o que a IA já entendeu junto das perguntas — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ao pedir clarificação (leitura da conta e análise do dono), persistir e exibir, somente leitura, o que a IA já entendeu — itens/valores na extração e claims por participante na análise.

**Architecture:** Reutiliza os JSONs existentes (`clarifications` / `analysis_clarifications`) adicionando a chave `understood`. O backend preenche essa chave nos jobs a partir do resultado parcial do modelo; o Vue renderiza um painel read-only acima das perguntas. Sem migration e sem mudança nos eventos de broadcast.

**Tech Stack:** PHP 8.3 / Laravel 13, Pest, Vue 3 + Inertia, Tailwind. Todos os comandos rodam no container (`docker compose exec app ...`).

**Spec:** `docs/superpowers/specs/2026-06-11-ai-clarification-partial-context-design.md`

---

## Estrutura de arquivos

- `app/Services/Receipt/ExtractionResult.php` — `requestInput()` passa a carregar itens/totais parciais.
- `app/Services/Receipt/PrismReceiptExtractor.php` — prompt instrui preencher parcial no `needs_input`; mapeia itens parciais e passa para `requestInput()`.
- `app/Jobs/ExtractReceiptItems.php` — `requestClarification()` recebe o `ExtractionResult` e grava `clarifications['understood']`.
- `app/Services/Bill/PrismBillSplitter.php` — prompt instrui preencher `claims` parciais no `needs_input`.
- `app/Jobs/AnalyzeBill.php` — ramo `needs_input` grava `analysis_clarifications['understood']['claims']` com `participant_name` resolvido.
- `resources/js/Pages/Sessions/Show.vue` — dois painéis read-only "O que a IA já entendeu até agora".
- `tests/Feature/ReceiptExtractionTest.php` — asserts de `understood` nos dois caminhos da extração.
- `tests/Feature/AnalyzeBillJobTest.php` — novo teste de `understood.claims` na análise.

---

## Task 1: Extração — persistir `understood` no caminho de dúvida da IA

**Files:**
- Modify: `app/Services/Receipt/ExtractionResult.php:55-58`
- Modify: `app/Jobs/ExtractReceiptItems.php:74-84`, `:96-107`, `:156-173`
- Modify: `app/Services/Receipt/PrismReceiptExtractor.php:71-78`, `:110-123`
- Test: `tests/Feature/ReceiptExtractionTest.php`

- [ ] **Step 1: Write the failing test**

Adicionar ao final de `tests/Feature/ReceiptExtractionTest.php`:

```php
test('the clarification payload includes what the AI already understood', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new class implements ReceiptExtractor
    {
        public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
        {
            return ExtractionResult::requestInput(
                questions: [['id' => 'q1', 'prompt' => 'Caipirinha é Comida ou Bebida?', 'type' => 'choice', 'options' => ['Comida', 'Bebida']]],
                raw: ['status' => 'needs_input'],
                items: [
                    ['name' => 'Heineken', 'quantity' => 2.0, 'unit_price' => 9.90, 'total_price' => 19.80, 'category' => 'drink'],
                ],
                subtotal: 19.80,
                serviceCharge: 0.0,
                total: 19.80,
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
        ->and($session->clarifications['understood']['items'])->toHaveCount(1)
        ->and($session->clarifications['understood']['items'][0]['name'])->toBe('Heineken')
        ->and((float) $session->clarifications['understood']['subtotal'])->toBe(19.80)
        ->and((float) $session->clarifications['understood']['total'])->toBe(19.80);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="what the AI already understood"`
Expected: FAIL — `requestInput()` ainda não aceita `items`/`subtotal`/`total` (erro de argumento desconhecido) ou `understood` ausente.

- [ ] **Step 3: Update `ExtractionResult::requestInput`**

Substituir o método em `app/Services/Receipt/ExtractionResult.php` (linhas 51-58):

```php
    /**
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: string}>  $items
     */
    public static function requestInput(
        array $questions,
        array $raw,
        array $items = [],
        float $subtotal = 0.0,
        float $serviceCharge = 0.0,
        float $total = 0.0,
    ): self {
        return new self(
            status: 'needs_input',
            items: $items,
            subtotal: $subtotal,
            serviceCharge: $serviceCharge,
            total: $total,
            questions: $questions,
            raw: $raw,
        );
    }
```

- [ ] **Step 4: Update `ExtractReceiptItems` to build `understood` from the result**

Em `app/Jobs/ExtractReceiptItems.php`, trocar a chamada no caminho de dúvida (linha 75) de:

```php
            $this->requestClarification($round, $answered, $result->questions, $result->raw);
```

para:

```php
            $this->requestClarification($round, $answered, $result->questions, $result);
```

Trocar a chamada no caminho da reconciliação (linha 98) de:

```php
                $this->requestClarification($round, $answered, $reconQuestions, $result->raw);
```

para:

```php
                $this->requestClarification($round, $answered, $reconQuestions, $result);
```

Substituir o método `requestClarification` inteiro (linhas 148-173) por:

```php
    /**
     * Estaciona a sessão aguardando esclarecimento do dono, reaproveitado tanto
     * pelas perguntas da IA quanto pelas divergências da reconciliação. Inclui em
     * "understood" o que a IA já leu (itens + totais) para dar contexto à pergunta.
     *
     * @param  array<int, array{question: string, answer: string}>  $answered
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     */
    private function requestClarification(int $round, array $answered, array $questions, ExtractionResult $result): void
    {
        $this->session->forceFill([
            'status' => ExtractionStatus::NeedsClarification,
            'clarifications' => [
                'round' => $round,
                'answered' => $answered,
                'pending' => $questions,
                'understood' => [
                    'items' => $result->items,
                    'subtotal' => $result->subtotal,
                    'service_charge' => $result->serviceCharge,
                    'total' => $result->total,
                ],
            ],
            'raw_extraction' => $result->raw,
            'failure_reason' => null,
        ])->save();

        event(new ReceiptExtractionUpdated(
            $this->session->id,
            ExtractionStatus::NeedsClarification->value,
        ));
    }
```

(O `use App\Services\Receipt\ExtractionResult;` já existe em `app/Jobs/ExtractReceiptItems.php:8`.)

- [ ] **Step 5: Update `PrismReceiptExtractor` prompt + carry partial items**

Em `app/Services/Receipt/PrismReceiptExtractor.php`, trocar a última frase do prompt (linhas 75-78) de:

```php
            .'Se a taxa de serviço não existir, use 0. NÃO ADIVINHE: se tiver qualquer '
            .'dúvida sobre a categoria de um item ou não conseguir ler um valor, retorne '
            .'status "needs_input" com perguntas objetivas em "questions" (uma por dúvida), '
            .'e deixe "items" vazio. Caso contrário, retorne status "complete".';
```

para:

```php
            .'Se a taxa de serviço não existir, use 0. NÃO ADIVINHE: se tiver qualquer '
            .'dúvida sobre a categoria de um item ou não conseguir ler um valor, retorne '
            .'status "needs_input" com perguntas objetivas em "questions" (uma por dúvida). '
            .'MESMO ao perguntar, preencha "items", subtotal, taxa e total com tudo o que '
            .'você JÁ leu com confiança (parcial) — isso serve para mostrar ao usuário o que '
            .'você já entendeu, mas NÃO substitui as perguntas. Caso contrário, status "complete".';
```

Substituir o bloco `needs_input` (linhas 110-123) por:

```php
        if (($data['status'] ?? 'complete') === 'needs_input' && ! $forceFinal) {
            $questions = array_map(fn (array $q): array => [
                'id' => (string) ($q['id'] ?? Str::uuid()),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'type' => in_array($q['type'] ?? 'text', ['choice', 'text'], true) ? $q['type'] : 'text',
                'options' => array_values(array_map('strval', $q['options'] ?? [])),
            ], $data['questions'] ?? []);

            $partialItems = array_map(fn (array $item): array => [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'total_price' => (float) ($item['total_price'] ?? 0),
                'category' => in_array($item['category'] ?? null, ['food', 'drink'], true) ? $item['category'] : 'food',
            ], $data['items'] ?? []);

            Log::info('[Service][PrismReceiptExtractor][extract] Modelo retornou perguntas de esclarecimento. Fim da execusão.', [
                'perguntas' => count($questions),
                'itens_parciais' => count($partialItems),
            ]);

            return ExtractionResult::requestInput(
                questions: $questions,
                raw: $data,
                items: $partialItems,
                subtotal: (float) ($data['subtotal'] ?? 0),
                serviceCharge: (float) ($data['service_charge'] ?? 0),
                total: (float) ($data['total'] ?? 0),
            );
        }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=ReceiptExtraction`
Expected: PASS — incluindo o novo teste e os existentes (que usam argumentos nomeados em `requestInput`, sem quebra).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Receipt/ExtractionResult.php app/Services/Receipt/PrismReceiptExtractor.php app/Jobs/ExtractReceiptItems.php tests/Feature/ReceiptExtractionTest.php
git commit -m "feat(extraction): carry the AI's partial read into clarification context"
```

---

## Task 2: Extração — `understood` também no caminho da reconciliação

**Files:**
- Test: `tests/Feature/ReceiptExtractionTest.php`

- [ ] **Step 1: Write the failing test**

Adicionar ao final de `tests/Feature/ReceiptExtractionTest.php`:

```php
test('the reconciliation clarification also exposes the items the AI read', function () {
    Event::fake();
    $this->app->instance(ReceiptExtractor::class, new class implements ReceiptExtractor
    {
        public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
        {
            // Lê "complete" mas a soma (30 + 20 = 50) não bate com o subtotal (48).
            return ExtractionResult::complete(
                items: [
                    ['name' => 'Cerveja', 'quantity' => 2.0, 'unit_price' => 15.0, 'total_price' => 30.0, 'category' => 'drink'],
                    ['name' => 'Batata', 'quantity' => 1.0, 'unit_price' => 20.0, 'total_price' => 20.0, 'category' => 'food'],
                ],
                subtotal: 48.0,
                serviceCharge: 5.0,
                serviceChargePercentage: 10.0,
                total: 53.0,
                raw: ['status' => 'complete'],
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
        ->and($session->clarifications['understood']['items'])->toHaveCount(2)
        ->and($session->clarifications['understood']['items'][0]['name'])->toBe('Cerveja')
        ->and((float) $session->clarifications['understood']['total'])->toBe(53.0);
});
```

- [ ] **Step 2: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter="exposes the items the AI read"`
Expected: PASS imediatamente — a Task 1 já faz o `requestClarification` ler `understood` do `$result`, e no caminho de reconciliação o `$result` (complete) já traz itens/totais. Este teste trava esse comportamento contra regressão.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ReceiptExtractionTest.php
git commit -m "test(extraction): lock reconciliation clarification exposing read items"
```

---

## Task 3: Análise — persistir `understood.claims` com nome do participante

**Files:**
- Modify: `app/Jobs/AnalyzeBill.php:70-90`
- Modify: `app/Services/Bill/PrismBillSplitter.php:159-165`
- Test: `tests/Feature/AnalyzeBillJobTest.php`

- [ ] **Step 1: Write the failing test**

Adicionar os imports no topo de `tests/Feature/AnalyzeBillJobTest.php` (após a linha 12 `use App\Services\Bill\BillSplitter;`):

```php
use App\Services\Bill\SplitResult;
```

Adicionar ao final de `tests/Feature/AnalyzeBillJobTest.php`:

```php
it('persists what the AI understood (claims by participant name) when it asks for clarification', function () {
    Event::fake([ReceiptAnalysisUpdated::class, BillAnalysisCompleted::class]);

    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'analysis_status' => AnalysisStatus::Processing,
    ]);
    $participant = SessionParticipant::factory()->for($session, 'session')->create(['name' => 'William']);
    $session->load('items', 'participants');

    $this->app->instance(BillSplitter::class, new class implements BillSplitter
    {
        public function split(Session $session, array $participants, bool $foodShared, array $answered = [], bool $forceFinal = false): SplitResult
        {
            return SplitResult::requestInput(
                questions: [['id' => 'q1', 'prompt' => 'Quem pediu o Moscow Mule?', 'type' => 'text', 'options' => []]],
                raw: ['claims' => [
                    ['participant_id' => $participants[0]['id'], 'items' => [['name' => 'Heineken', 'quantity' => 2.0]]],
                ]],
            );
        }
    });

    (new AnalyzeBill($session))->handle(app(BillSplitter::class));
    $session->refresh();

    expect($session->analysis_status)->toBe(AnalysisStatus::NeedsClarification)
        ->and($session->analysis_clarifications['pending'])->toHaveCount(1)
        ->and($session->analysis_clarifications['understood']['claims'])->toHaveCount(1)
        ->and($session->analysis_clarifications['understood']['claims'][0]['participant_name'])->toBe('William')
        ->and($session->analysis_clarifications['understood']['claims'][0]['items'][0]['name'])->toBe('Heineken');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter="claims by participant name"`
Expected: FAIL — `analysis_clarifications['understood']` ainda não é gravado (chave inexistente).

- [ ] **Step 3: Update `AnalyzeBill` needs_input branch**

Em `app/Jobs/AnalyzeBill.php`, substituir o bloco `needs_input` (linhas 70-90) por:

```php
        if ($result->needsInput() && ! $forceFinal) {
            $nameById = $this->session->participants->pluck('name', 'id');

            $understoodClaims = array_map(fn (array $c): array => [
                'participant_name' => (string) ($nameById[$c['participant_id'] ?? ''] ?? ($c['participant_id'] ?? '')),
                'items' => array_map(fn (array $i): array => [
                    'name' => (string) ($i['name'] ?? ''),
                    'quantity' => (float) ($i['quantity'] ?? 0),
                ], $c['items'] ?? []),
            ], $result->raw['claims'] ?? []);

            $this->session->forceFill([
                'analysis_status' => AnalysisStatus::NeedsClarification,
                'analysis_clarifications' => [
                    'round' => $round,
                    'answered' => $answered,
                    'pending' => $result->questions,
                    'understood' => ['claims' => $understoodClaims],
                ],
                'analysis_failure_reason' => null,
            ])->save();

            event(new ReceiptAnalysisUpdated($this->session->id, AnalysisStatus::NeedsClarification->value));

            Log::info('[Job][AnalyzeBill][handle] Análise precisa de esclarecimento. Fim da execusão.', [
                'session_id' => $this->session->id,
                'perguntas' => count($result->questions),
                'claims_parciais' => count($understoodClaims),
                'round' => $round,
            ]);

            return;
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app php artisan test --filter="claims by participant name"`
Expected: PASS.

- [ ] **Step 5: Update `PrismBillSplitter` prompt to fill partial claims**

Em `app/Services/Bill/PrismBillSplitter.php`, trocar a frase do prompt (linhas 161-165) de:

```php
            .'Para cada participante, liste em "claims" os itens que ELE consumiu, usando o '
            .'nome EXATO do item na conta e a quantidade. NÃO calcule valores nem gorjeta. '
            .'NÃO invente: se algo estiver ambíguo (ex.: alguém citou um item que não está na '
            .'conta, ou uma quantidade não bate), retorne status "needs_input" com perguntas '
            .'objetivas em "questions" e deixe "claims" vazio. Caso contrário, status "complete".';
```

para:

```php
            .'Para cada participante, liste em "claims" os itens que ELE consumiu, usando o '
            .'nome EXATO do item na conta e a quantidade. NÃO calcule valores nem gorjeta. '
            .'NÃO invente: se algo estiver ambíguo (ex.: alguém citou um item que não está na '
            .'conta, ou uma quantidade não bate), retorne status "needs_input" com perguntas '
            .'objetivas em "questions". MESMO ao perguntar, preencha "claims" com as atribuições '
            .'de que você JÁ tem certeza (parcial) — serve para mostrar ao dono o que você já '
            .'entendeu, mas NÃO substitui as perguntas. Caso contrário, status "complete".';
```

- [ ] **Step 6: Run the analysis test suite**

Run: `docker compose exec app php artisan test --filter=AnalyzeBill`
Expected: PASS — o novo teste e o existente (`completes analysis ...`) passam.

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/AnalyzeBill.php app/Services/Bill/PrismBillSplitter.php tests/Feature/AnalyzeBillJobTest.php
git commit -m "feat(analysis): carry the AI's partial claims into clarification context"
```

---

## Task 4: Frontend — painel "O que a IA já entendeu" na extração

**Files:**
- Modify: `resources/js/Pages/Sessions/Show.vue:336-337`

- [ ] **Step 1: Add the read-only panel above the extraction questions**

Em `resources/js/Pages/Sessions/Show.vue`, dentro do bloco `v-else-if="session.status === 'needs_clarification'"`, inserir entre o parágrafo de subtítulo (linha 335, `</p>` de "Responda para concluir...") e o `<form>` (linha 337):

```html
                            <div
                                v-if="(session.clarifications?.understood?.items ?? []).length"
                                class="mt-4 rounded-md border border-hairline bg-surface-card p-3"
                            >
                                <p class="text-xs font-medium text-muted">O que a IA já entendeu até agora</p>
                                <ul class="mt-2 space-y-1 text-sm text-body">
                                    <li
                                        v-for="(item, idx) in session.clarifications.understood.items"
                                        :key="idx"
                                        class="flex justify-between gap-2"
                                    >
                                        <span>{{ Number(item.quantity) }}x {{ item.name }}</span>
                                        <span class="text-muted">{{ brl(item.total_price) }}</span>
                                    </li>
                                </ul>
                                <div class="mt-2 flex justify-between gap-2 border-t border-hairline pt-2 text-sm">
                                    <span class="text-muted">Subtotal</span>
                                    <span class="text-ink">{{ brl(session.clarifications.understood.subtotal) }}</span>
                                </div>
                                <div class="flex justify-between gap-2 text-sm font-medium">
                                    <span class="text-muted">Total</span>
                                    <span class="text-ink">{{ brl(session.clarifications.understood.total) }}</span>
                                </div>
                            </div>

```

(`Number` já é global no template; `brl` está definido em `Show.vue:56`.)

- [ ] **Step 2: Build assets and verify no template error**

Run: `docker compose exec app npm run build`
Expected: build conclui sem erros de template Vue.

- [ ] **Step 3: Manual verification**

Subir a stack (`docker compose up -d`), abrir uma sessão cuja extração esteja em `needs_clarification` com `clarifications.understood.items` preenchido (gerado pela Task 1 num fluxo real, ou ajustar manualmente via tinker para teste visual). Confirmar que o painel "O que a IA já entendeu até agora" aparece acima das perguntas, listando itens + subtotal/total, e some quando `understood.items` está vazio.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Sessions/Show.vue
git commit -m "feat(ui): show the AI's partial read above receipt clarification questions"
```

---

## Task 5: Frontend — painel "O que a IA já entendeu" na análise

**Files:**
- Modify: `resources/js/Pages/Sessions/Show.vue:537-539`

- [ ] **Step 1: Add the read-only panel above the analysis questions**

Em `resources/js/Pages/Sessions/Show.vue`, dentro do bloco `v-else-if="session.analysis_status === 'needs_clarification'"`, inserir entre o parágrafo de subtítulo (linha 537, `</p>` de "Responda para fechar...") e o `<form>` (linha 539):

```html
                                    <div
                                        v-if="(session.analysis_clarifications?.understood?.claims ?? []).length"
                                        class="mt-4 rounded-md border border-hairline bg-surface-card p-3"
                                    >
                                        <p class="text-xs font-medium text-muted">O que a IA já entendeu até agora</p>
                                        <div
                                            v-for="(claim, idx) in session.analysis_clarifications.understood.claims"
                                            :key="idx"
                                            class="mt-2"
                                        >
                                            <p class="text-sm font-medium text-ink">{{ claim.participant_name }}</p>
                                            <ul class="mt-1 space-y-0.5 text-sm text-body">
                                                <li v-for="(item, i) in (claim.items ?? [])" :key="i">
                                                    {{ Number(item.quantity) }}x {{ item.name }}
                                                </li>
                                                <li v-if="!(claim.items ?? []).length" class="text-muted">
                                                    (nada atribuído ainda)
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

```

- [ ] **Step 2: Build assets and verify no template error**

Run: `docker compose exec app npm run build`
Expected: build conclui sem erros de template Vue.

- [ ] **Step 3: Manual verification**

Abrir uma sessão cuja análise esteja em `needs_clarification` com `analysis_clarifications.understood.claims` preenchido. Confirmar que o painel aparece acima das perguntas, agrupando por nome do participante os itens já atribuídos, e some quando não há claims.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Sessions/Show.vue
git commit -m "feat(ui): show the AI's partial claims above analysis clarification questions"
```

---

## Verificação final

- [ ] Rodar a suíte completa: `docker compose exec app composer run test` → tudo verde.
- [ ] Rodar o Pint: `docker compose exec app ./vendor/bin/pint` → sem mudanças pendentes (ou commitar o que ele ajustar).
