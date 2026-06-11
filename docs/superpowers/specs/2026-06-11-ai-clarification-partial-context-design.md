# Mostrar o que a IA já entendeu junto das perguntas de clarificação

**Data:** 2026-06-11
**Status:** Aprovado (pendente revisão do spec)

## Problema

Quando o usuário aciona "Ler com IA" (extração da conta) e a IA tem uma dúvida,
a tela mostra apenas as perguntas ("A IA tem algumas dúvidas") sem nenhum contexto
do que ela já leu. O usuário responde no escuro, sem saber o que a IA já entendeu
daquela conta. O mesmo acontece no fluxo de análise do dono (`AnalyzeBill`).

O pedido: ao perguntar, a IA já deve trazer os itens e valores que ela entendeu,
para o usuário **entender o que ela já sabe** antes de responder.

## Decisões de escopo

- **Exibição: somente leitura (informativo).** Mostra o que a IA entendeu apenas
  para dar contexto à pergunta. O usuário continua só respondendo as perguntas.
  Edição dos itens parciais está **fora de escopo**.
- **Abrange os dois fluxos:** leitura da conta (`ExtractReceiptItems` / "Ler com IA")
  **e** análise do dono (`AnalyzeBill`), que compartilham o mesmo padrão de perguntas.

## Estado atual (referência)

- Extração: `PrismReceiptExtractor::extract()` instrui o modelo a deixar `items`
  vazio quando `status = needs_input` (prompt diz "deixe items vazio"), e retorna
  `ExtractionResult::requestInput($questions, $raw)` — descartando qualquer item.
  O `ExtractReceiptItems::requestClarification()` grava `clarifications` com
  `round/answered/pending`. Há **dois caminhos** que pedem clarificação:
  (1) dúvida da própria IA (`needs_input`) e (2) reconciliação
  (`ReceiptReconciliation::check`) quando a conta não fecha — neste segundo caso
  `$result->items` **já está populado**, só não é exibido.
- Análise: `PrismBillSplitter::matchClaims()` instrui o modelo a deixar `claims`
  vazio quando `needs_input`. `AnalyzeBill` grava `analysis_clarifications` com
  `round/answered/pending`. O que a IA "já sabe" aqui são os `claims`
  (quem-consumiu-o-quê); valores em R$ só são calculados no `BillReconciler` no
  caminho completo.
- UI: `resources/js/Pages/Sessions/Show.vue` renderiza os dois blocos
  `needs_clarification` lendo `session.clarifications?.pending` e
  `session.analysis_clarifications?.pending`.

## Modelo de dados (sem migration)

Reutilizar os JSONs existentes (`clarifications` / `analysis_clarifications`),
adicionando uma nova chave `understood` ao lado de `round/answered/pending`:

- Extração — `clarifications['understood']`:
  ```
  {
    items: [{ name, quantity, unit_price, total_price, category }],
    subtotal: float,
    service_charge: float,
    total: float
  }
  ```
- Análise — `analysis_clarifications['understood']`:
  ```
  {
    claims: [{ participant_name: string, items: [{ name, quantity }] }]
  }
  ```

## Backend

### Extração

1. **`PrismReceiptExtractor`** — trocar a instrução do prompt: em vez de
   "deixe items vazio" no `needs_input`, pedir para **preencher items/totais com
   aquilo que já tem certeza** (parcial), mantendo as perguntas obrigatórias. Deixar
   explícito que o preenchimento parcial é só para mostrar ao usuário e não dispensa
   as perguntas. `ExtractionResult::requestInput()` passa a carregar esses
   items/totais nos campos que o objeto **já possui** (`items`, `subtotal`,
   `serviceCharge`, `total`) — sem inventar estrutura nova.
2. **`ExtractReceiptItems::requestClarification()`** — montar `understood` a partir
   do `$result` (items + subtotal + service_charge + total) e gravar em
   `clarifications['understood']`. Como os **dois caminhos** (dúvida da IA e
   reconciliação) passam por esse método, o caminho da reconciliação ganha de
   brinde a exibição do que foi lido.

### Análise

1. **`PrismBillSplitter::matchClaims()`** — trocar "deixe claims vazio" por
   **preencher `claims` com as atribuições de que já tem certeza**, mantendo as
   perguntas. Os claims já voltam no `$data`/`raw` do `SplitResult`.
2. **`AnalyzeBill`** — no ramo `needs_input`, mapear `participant_id → nome`
   (a partir de `$this->session->participants`) e gravar
   `analysis_clarifications['understood']['claims']` com `participant_name` resolvido.

## Frontend (`Show.vue`)

Painel **read-only acima** das perguntas, em ambos os blocos `needs_clarification`:

- **Extração** — título "O que a IA já entendeu até agora": lista
  `{quantity}x {name} — {brl(total_price)}` + linha de subtotal/total. Renderiza
  só se `session.clarifications?.understood?.items?.length`.
- **Análise** — título "O que a IA já entendeu até agora": por participante
  (`understood.claims`), `nome` → itens `{quantity}x {name}`. Renderiza só se
  houver `claims`.
- Estilo reaproveitando os cards existentes (`border-hairline bg-surface-strong`),
  cópia em PT-BR, sem componente novo (segue a convenção da tela de usar markup
  inline e os primitivos já presentes).

## Testes (Pest, TDD — escrever antes da implementação)

1. Extração, dúvida da IA: fake `ReceiptExtractor` retorna `requestInput` com
   items/totais parciais → assert `clarifications['understood']['items']`
   persistido e evento `ReceiptExtractionUpdated` inalterado.
2. Extração, reconciliação: conta não fecha → assert `understood` reflete os
   itens lidos pelo `$result`.
3. Análise, dúvida: fake `BillSplitter` retorna `requestInput` com `raw['claims']`
   → assert `analysis_clarifications['understood']['claims']` com `participant_name`
   resolvido a partir do `participant_id`.
4. Ajustar os testes existentes que chamam `requestInput` (usam argumentos
   nomeados, então continuam válidos; adicionar asserts onde fizer sentido).

## Casos de borda

- `understood` ausente/vazio (sessões antigas no meio do fluxo, ou rodada final
  `forceFinal` que não gera perguntas) → o painel some, sem regressão.

## Fora de escopo (YAGNI)

- Edição dos itens/valores parciais pelo usuário.
- Qualquer migration ou coluna nova.
- Mudar o contrato dos eventos de broadcast (`ReceiptExtractionUpdated`,
  `ReceiptAnalysisUpdated`).
