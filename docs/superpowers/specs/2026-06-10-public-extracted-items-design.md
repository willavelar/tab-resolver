# Spec: Itens da conta + Resumo na página pública

**Data:** 2026-06-10
**Status:** Aprovado para planejamento
**Escopo:** Trocar a exibição da imagem da nota pelos itens extraídos e o
resumo, na página pública (`/c/{token}`), quando a extração via IA já estiver
concluída.

## Contexto e objetivo

Hoje a página pública (`Public/Session.vue`) sempre mostra a foto da nota
fiscal, para que o participante identifique o que consumiu. Quando a extração
por IA já foi concluída, a conta já tem `Itens da conta` (agrupados por
Comida/Bebida, com Sub-total/Gorjeta/Total) e um `Resumo` em texto — ambos já
exibidos na página autenticada (`Sessions/Show.vue`).

Objetivo: quando `session.status === 'completed'`, a página pública mostra
**Itens da conta** + **Resumo** no lugar da imagem. Em qualquer outro status
(`pending`, `processing`, `needs_clarification`, `failed`), continua mostrando
a imagem como hoje.

## Decisões de produto

- Troca limpa (não mostra os dois ao mesmo tempo): `completed` → itens/resumo;
  outros status → imagem.
- O formulário de participação (nome + áudio/texto) permanece inalterado,
  abaixo do bloco de itens/imagem, em qualquer status.
- O botão "📋 Copiar resumo" (presente em `Show.vue`) **não** é replicado na
  página pública — o `Resumo` aparece apenas como texto (`<pre>`), sem ação de
  copiar.
- Sem atualização em tempo real (Reverb) nesta página: os dados refletem o
  estado no momento do carregamento da página.

## Mudanças no backend

### `PublicSessionController::show`

- `$session->load(['items'])` (além do que já é carregado).
- Estender o prop `session` com os mesmos campos públicos usados em
  `SessionController::show`:
  - `status` → `$session->status->value`
  - `subtotal`, `service_charge`, `service_charge_percentage`, `total`
  - `items` → `$session->items->map(...)` com `id, name, quantity, unit_price,
    total_price, category`
  - `summary_markdown` → `ReceiptSummary::for($session)` quando
    `status === Completed`, senão `null`
- `image_url` continua sendo enviado sempre (usado no fallback).

Nenhuma mudança em `PublicSessionController::store` ou nas rotas.

## Mudanças no frontend

### `resources/js/Pages/Public/Session.vue`

- Adicionar `brl()` (formatador de moeda) e os computeds `foodItems` /
  `drinkItems`, copiados de `Sessions/Show.vue`.
- Substituir o bloco único `<img>` por:
  - `v-if="session.status === 'completed'"` → bloco "Itens da conta"
    (tabelas Comida/Bebida + Sub-total/Gorjeta/Total) + bloco "Resumo"
    (`<pre>` com `session.summary_markdown`, sem botão de copiar), reutilizando
    a marcação de `Show.vue`.
  - `v-else` → o `<img>` atual (sem alterações).
- Nenhuma mudança no formulário de participação, em `AudioRecorder`, ou no
  fluxo de envio.

## Testes

- Pest feature test em `PublicSessionController::show`:
  - Sessão `completed` com itens → resposta inclui `items` preenchidos e
    `summary_markdown` não nulo.
  - Sessão em outro status (ex.: `pending`) → `items` vazio,
    `summary_markdown` nulo, `image_url` presente.

## Fora do escopo

- Atualização em tempo real via Reverb na página pública.
- Mudanças de autenticação/autorização do link público (token continua sendo
  o único controle de acesso).
- Botão de copiar resumo na página pública.
