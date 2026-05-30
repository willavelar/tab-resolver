# TabResolver — Design Spec

**Data:** 2026-05-30  
**Status:** Aprovado

---

## Visão Geral

TabResolver é uma aplicação web que analisa a conta de um restaurante (via foto) e, com base em áudios ou texto de cada participante, calcula automaticamente quanto cada pessoa deve pagar — usando IA para leitura da conta, transcrição de áudio e mapeamento de pedidos.

---

## Decisões de Design

| Decisão | Escolha |
|---|---|
| Framework backend | Laravel 13 |
| Frontend | Inertia.js + Vue 3 |
| Infraestrutura | Docker Compose |
| Modelo de sessão | Híbrido: criador autenticado + convidados por link |
| Autenticação | Laravel Breeze (criador); join_token (convidados) |
| Entrada de pedido | Gravação de áudio no browser (MediaRecorder API) + fallback texto |
| IA para conta | Claude claude-opus-4-7 (Vision) |
| IA para áudio | OpenAI Whisper API |
| IA para mapeamento e cálculo | GPT-4o |
| Processamento | Laravel Jobs (filas assíncronas, driver Redis) |
| Tempo real | Laravel Reverb (WebSockets) |
| Taxas e gorjeta | Proporcionais ao subtotal de cada participante |
| Itens compartilhados | IA detecta automaticamente via linguagem natural |

---

## Arquitetura

### Serviços Docker Compose

```
┌─────────────────┬──────────────────────────────────────────┐
│ laravel-app     │ PHP 8.3-FPM + Nginx · porta 80           │
│                 │ HTTP, Inertia, auth, dispatch de jobs     │
├─────────────────┼──────────────────────────────────────────┤
│ queue-worker    │ mesma imagem PHP · sem porta exposta      │
│                 │ php artisan queue:work                    │
│                 │ Consome: Whisper, Claude, GPT-4o          │
├─────────────────┼──────────────────────────────────────────┤
│ mysql           │ MySQL 8 · porta 3306                      │
├─────────────────┼──────────────────────────────────────────┤
│ redis           │ Redis 7 · porta 6379                      │
│                 │ Fila de jobs + cache + Reverb pub/sub     │
├─────────────────┼──────────────────────────────────────────┤
│ reverb          │ Laravel Reverb · porta 8080               │
│                 │ WebSocket server para broadcast           │
└─────────────────┴──────────────────────────────────────────┘
```

### Fluxo de dados

```
Browser → laravel-app → Redis Queue → queue-worker → AI APIs
                                                          ↓
Browser ← Reverb (WS) ← Redis Pub/Sub ← queue-worker (broadcast)
```

### Stack completo

- **Backend:** Laravel 13, PHP 8.3, Laravel Horizon (monitor de filas)
- **Frontend:** Inertia.js, Vue 3, Tailwind CSS v4, Vite
- **IA:** Claude claude-opus-4-7 (visão), OpenAI Whisper (áudio), GPT-4o (mapeamento e cálculo)
- **Infra:** MySQL 8, Redis 7, Laravel Reverb, Docker Compose

---

## Modelo de Dados

### `users`
| Campo | Tipo | Descrição |
|---|---|---|
| id | ulid PK | |
| name | varchar | |
| email | varchar unique | |
| password | hash | |
| timestamps | | |

### `sessions`
| Campo | Tipo | Descrição |
|---|---|---|
| id | ulid PK | |
| creator_id | FK → users | |
| title | varchar | Nome da sessão |
| share_token | varchar unique | Token do link de convite |
| status | enum | `draft` \| `open` \| `processing` \| `done` |
| image_path | varchar nullable | Foto da conta no storage |
| bill_raw_json | json nullable | Output bruto da IA (Claude) |
| subtotal | decimal | Total sem taxas |
| fees_amount | decimal | Total de taxas/gorjeta |
| timestamps | | |

### `participants`
| Campo | Tipo | Descrição |
|---|---|---|
| id | ulid PK | |
| session_id | FK → sessions | |
| name | varchar | |
| join_token | varchar unique | Token individual do convidado |
| audio_path | varchar nullable | Arquivo de áudio gravado |
| transcription | text nullable | Texto transcrito pelo Whisper ou digitado |
| status | enum | `pending` \| `submitted` |
| total_due | decimal nullable | Valor final calculado |
| timestamps | | |

### `bill_items`
| Campo | Tipo | Descrição |
|---|---|---|
| id | ulid PK | |
| session_id | FK → sessions | |
| description | varchar | Nome do item |
| unit_price | decimal | |
| quantity | decimal | |
| total_price | decimal | |
| is_fee | boolean | true para gorjeta, taxa de serviço, couvert |
| created_at | | |

### `participant_items`
| Campo | Tipo | Descrição |
|---|---|---|
| id | ulid PK | |
| participant_id | FK → participants | |
| bill_item_id | FK → bill_items | |
| share_fraction | decimal | 1.0 = integral, 0.5 = metade, etc. |
| amount | decimal | Valor calculado (unit_price × quantity × share_fraction) |
| created_at | | |

### `ai_jobs`
| Campo | Tipo | Descrição |
|---|---|---|
| id | ulid PK | |
| session_id | FK → sessions | |
| participant_id | FK nullable → participants | |
| type | enum | `bill_scan` \| `transcription` \| `calculate` |
| status | enum | `pending` \| `running` \| `done` \| `failed` |
| result | json nullable | Output da API de IA |
| error | text nullable | Mensagem de erro em caso de falha |
| timestamps | | |

---

## Fluxo de Telas

### Tela 1 — Dashboard (`/dashboard`)
- Lista de sessões do criador (nome, número de participantes, status, total)
- Botão "Nova Sessão"
- Acessível apenas para usuários autenticados

### Tela 2 — Criar Sessão (`/sessions/create`)
- Campo título
- Upload de foto da conta (JPG, PNG, HEIC)
- Ao submeter: cria `session` com `status=draft`, dispara `ScanBillImage` job
- Redireciona para Tela 3

### Tela 3 — Gerenciar Sessão (`/sessions/{id}`)
- Link de convite (`/join/{share_token}`) com botão copiar
- Lista de participantes com status em tempo real (via Reverb)
- Botão "Calcular" habilitado apenas quando todos enviaram
- Exibe preview dos itens extraídos da conta

### Tela 4 — Entrada do Convidado (`/join/{share_token}`)
- Campo nome (se primeiro acesso)
- Botão gravar áudio (MediaRecorder API) ou caixa de texto
- Botão "Enviar pedido"
- Após envio: tela de aguardo com mensagem "Aguardando o cálculo..."
- Ao receber evento `SessionCompleted`: redireciona para Tela 5

### Tela 5 — Resultado (`/sessions/{id}/results`)
- Card por participante: nome, itens consumidos, subtotal, taxa proporcional, **total devido**
- Total geral da conta
- Acessível por todos (criador e convidados via token)

---

## Pipeline de IA

### Job 1: `ScanBillImage`
- **Trigger:** criação da sessão
- **Input:** caminho da imagem no storage
- **Modelo:** Claude claude-opus-4-7 (Vision)
- **Prompt:** Extrai todos os itens da conta em JSON estruturado `[{description, unit_price, quantity, total_price, is_fee}]`
- **Output:** persiste `bill_items`, atualiza `session.status = open`, broadcast `BillScanned`

### Job 2: `ProcessParticipantInput`
- **Trigger:** participante envia pedido
- **Input:** `audio_path` ou texto da transcrição
- **Paralelo:** 1 job por participante, rodam simultaneamente
- **Etapa 2a (se áudio):** OpenAI Whisper API → texto transcrito, persiste em `participant.transcription`
- **Etapa 2b:** GPT-4o recebe texto + lista de `bill_items` → mapeia pedido para itens reais com `share_fraction` (detecta divisões por linguagem natural)
- **Output:** persiste `participant_items`, broadcast `ParticipantSubmitted`

### Job 3: `CalculateFinalAmounts`
- **Trigger:** criador clica em "Calcular"
- **Input:** todos `participant_items` + `bill_items` com `is_fee=true`
- **Modelo:** GPT-4o
- **Lógica:**
  - Soma itens por participante
  - Distribui taxas proporcionalmente ao subtotal de cada um
  - Detecta itens não mapeados → divide igualmente entre todos
  - Retorna `[{participant_id, subtotal, fees_share, total_due, items[]}]`
- **Output:** atualiza `participants.total_due`, `session.status = done`, broadcast `SessionCompleted`

### Broadcast Events (Laravel Reverb)
| Evento | Canal | Efeito na UI |
|---|---|---|
| `BillScanned` | `session.{id}` | Exibe itens extraídos, habilita link de convite |
| `ParticipantSubmitted` | `session.{id}` | Marca participante como "enviado" em tempo real |
| `ProcessingFailed` | `session.{id}` | Exibe erro com opção de retentar |
| `SessionCompleted` | `session.{id}` | Redireciona todos para `/results` |

---

## Tratamento de Erros

- **Falha na leitura da conta:** `ai_jobs.status = failed`, broadcast `ProcessingFailed`, criador pode re-enviar a imagem
- **Falha na transcrição/mapeamento:** job pode ser retentado (3x automático via `$tries` do Laravel Job), participante notificado para regravar
- **Itens não mapeados:** Job 3 distribui igualmente entre todos — explicitado no resultado final
- **Timeout de API:** jobs com `$timeout = 120` segundos, falha registrada em `ai_jobs`

---

## Estrutura de Diretórios (Laravel)

```
app/
  Http/Controllers/
    SessionController.php
    ParticipantController.php
    ResultController.php
  Jobs/
    ScanBillImage.php
    ProcessParticipantInput.php
    CalculateFinalAmounts.php
  Events/
    BillScanned.php
    ParticipantSubmitted.php
    ProcessingFailed.php
    SessionCompleted.php
  Services/
    ClaudeVisionService.php
    WhisperService.php
    GptMappingService.php
  Models/
    Session.php
    Participant.php
    BillItem.php
    ParticipantItem.php
    AiJob.php
resources/
  js/
    Pages/
      Dashboard.vue
      Sessions/Create.vue
      Sessions/Show.vue
      Sessions/Results.vue
      Join/Index.vue
    Components/
      AudioRecorder.vue
      ParticipantList.vue
      BillItemsList.vue
      ResultCard.vue
docker/
  php/Dockerfile
  nginx/nginx.conf
docker-compose.yml
```
