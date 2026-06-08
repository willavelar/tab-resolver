# Spec: Envio público de participantes (coleta + tempo real)

**Data:** 2026-06-08
**Status:** Aprovado para planejamento
**Escopo:** Metade A — coleta pública + tempo real. O processamento por IA
(transcrição via Whisper, interpretação via Claude) e a relação de pagamento
ficam para uma spec futura separada.

## Contexto e objetivo

Hoje uma conta de restaurante (`bill_sessions`) só é acessível pelo dono logado.
O objetivo é permitir que **pessoas sem login** acessem um link de
compartilhamento exclusivo, vejam a imagem da nota e enviem **o nome** e uma
descrição do que consumiram — por **texto** (até 256 caracteres) ou por **áudio
gravado no navegador** (menos de 2 minutos). O dono da conta acompanha esses
envios chegando **em tempo real** via websocket.

O resultado final (relação de pagamento) será montado em uma etapa posterior,
fora desta spec: a IA, em outro momento, transcreverá todos os áudios (OpenAI
Whisper) e o Claude interpretará e casará com os itens da nota. **Nada de IA
acontece nesta spec** — aqui só coletamos e exibimos.

## Restrições conhecidas

- A API da Anthropic (Claude) **não aceita áudio** como entrada. A transcrição
  exigirá um serviço de speech-to-text (decidido: **OpenAI Whisper**) numa spec
  futura. Por isso o áudio é apenas **armazenado** aqui.
- Testes rodam em **SQLite in-memory**; produção em **MySQL 8**. Os dois tratam
  unicidade de string de forma diferente (collation), então a unicidade
  case-insensitive do nome é garantida na **validação**, não só no índice.

## Decisões de produto

- O link público fica **sempre ativo** assim que a conta é criada (sem botão de
  ligar/desligar).
- A página pública mostra **apenas a imagem da nota** e o título — não os itens
  extraídos.
- A unicidade do nome por conta é **case-insensitive** e com `trim`.
- Para enviar é obrigatório: **nome** + (**texto** ou **áudio**).

## Modelo de dados

### Alteração em `bill_sessions`

Nova coluna `public_token` (string, única, ~32 caracteres aleatórios),
gerada automaticamente na criação do registro. O ULID nunca é exposto
publicamente; o link usa esse token não-adivinhável.

- Migração via `php artisan make:migration` (`add_public_token_to_bill_sessions_table`).
- **Backfill** das contas existentes dentro da migração (gerar token para cada
  linha já presente) antes de aplicar o índice único.
- Geração automática no model via hook `booted()`/`creating` (`Str::random(32)`),
  garantindo token para toda conta nova sem depender do controller.

### Nova tabela `session_participants`

PK ULID (padrão do projeto — nunca auto-increment).

| coluna             | tipo                  | nota                                   |
| ------------------ | --------------------- | -------------------------------------- |
| `id`               | ulid (PK)             |                                        |
| `bill_session_id`  | foreignUlid           | `constrained()->cascadeOnDelete()`     |
| `name`             | string                | nome do participante                   |
| `text`             | string(256), nullable | descrição digitada                     |
| `audio_path`       | string, nullable      | caminho do áudio no disco `public`     |
| `audio_duration`   | integer, nullable     | duração em segundos (informada pelo cliente) |
| `timestamps`       |                       |                                        |

Índice único `(bill_session_id, name)` como rede de segurança. A unicidade
case-insensitive real é aplicada na validação.

### Models

- **`SessionParticipant`** — `HasUlids`, `HasFactory`; `belongsTo(Session, 'bill_session_id')`;
  `$fillable = ['bill_session_id', 'name', 'text', 'audio_path', 'audio_duration']`;
  cast `audio_duration => integer`.
- **`Session`** — adicionar `participants(): HasMany`; incluir `public_token` no
  fluxo de geração automática; ocultar/não expor `public_token` onde não for
  necessário; geração no `booted()`.

## Rotas (`routes/web.php`, **fora** do grupo `auth`)

```php
Route::get('/c/{token}', [PublicSessionController::class, 'show'])
    ->name('public.sessions.show');

Route::post('/c/{token}/participants', [PublicSessionController::class, 'store'])
    ->name('public.participants.store');
```

A conta é resolvida por `public_token` dentro do controller
(`Session::where('public_token', $token)->firstOrFail()`), sem alterar o
route-key padrão (ULID) usado pelas rotas autenticadas.

## Backend

### `PublicSessionController`

- **`show($token)`** — resolve a conta pelo token; `Inertia::render('Public/Session', [...])`
  com `image_url`, `title` e `token`. Sem middleware `auth`.
- **`store(StorePublicParticipantRequest $request, $token)`** — resolve a conta;
  se houver arquivo de áudio, armazena em `storage` (disco `public`, pasta
  `participant-audios`); cria o `SessionParticipant`; dispara
  `ParticipantSubmitted`; redireciona de volta com flash de sucesso.

### `StorePublicParticipantRequest`

Validação (em `App\Http\Requests`, não inline):

- `name`: `required`, `string`, `max:255`; **único por conta, case-insensitive**
  (regra com `whereRaw('LOWER(name) = ?')` escopada ao `bill_session_id`, com
  `trim`).
- `text`: `nullable`, `string`, `max:256`.
- `audio`: `nullable`, `file`, mimetypes de áudio (`audio/webm`, `audio/ogg`,
  `audio/mp4`), limite de tamanho coerente com ~2 min.
- `audio_duration`: `nullable`, `integer`, `max:120`.
- **Pelo menos um de `text`/`audio`**: `text` → `required_without:audio` e
  `audio` → `required_without:text`.
- Mensagens de erro em **PT-BR**.

## Tempo real (reaproveita o canal existente)

Novo evento **`App\Events\ParticipantSubmitted implements ShouldBroadcast`**,
no **mesmo** canal privado `bill-session.{id}` (já autorizado apenas para o dono
em `routes/channels.php`):

- `broadcastOn()` → `PrivateChannel('bill-session.'.$sessionId)`
- `broadcastAs()` → `'participant.submitted'`
- `broadcastWith()` → `['id', 'name', 'has_text', 'has_audio', 'created_at']`

O participante não-logado **não escuta** nenhum canal. Apenas o dono (já
autenticado e autorizado) recebe o evento.

## Frontend

### `Pages/Public/Session.vue` (layout público minimalista)

- Sem a navbar autenticada — novo layout público (ou `GuestLayout` adaptado).
- Exibe a **imagem da nota** + título.
- Formulário (`useForm` com `forceFormData`):
  - input de **nome**;
  - **textarea** com contador de 256 caracteres;
  - componente de **gravação de áudio**;
  - botão **Enviar** desabilitado até haver nome + (texto ou áudio).
- Estado de **"obrigado"** após envio bem-sucedido.
- Erros de validação exibidos via `InputError` (primitivas do Breeze).
- Copy em **PT-BR**.

### `Components/AudioRecorder.vue`

- Usa a **MediaRecorder API** — gravar capturando a voz ao apertar (não é
  upload de arquivo).
- Timer visível; **auto-stop em 120s**.
- Prévia para **ouvir** e opção de **regravar**.
- Produz um `Blob` anexado ao `useForm` junto com `audio_duration`.

### `Pages/Sessions/Show.vue` (dono)

- Nova seção **"Compartilhar"**: link público (`route('public.sessions.show', token)`)
  + botão **copiar**.
- Nova seção **"Participantes"**: lista os envios (carregados no load via props +
  **anexados ao vivo** com `channel.listen('.participant.submitted', ...)` no
  Echo já existente), mostrando nome, badges de **texto**/**áudio**, com o texto
  e o **player** do áudio para o dono.
- `SessionController@show` passa a carregar `participants` e expor `public_url`/`token`.

## Arquivos

**Criar:**
- `database/migrations/*_add_public_token_to_bill_sessions_table.php`
- `database/migrations/*_create_session_participants_table.php`
- `app/Models/SessionParticipant.php`
- `app/Http/Controllers/PublicSessionController.php`
- `app/Http/Requests/StorePublicParticipantRequest.php`
- `app/Events/ParticipantSubmitted.php`
- `resources/js/Pages/Public/Session.vue`
- `resources/js/Layouts/PublicLayout.vue` (ou adaptação do `GuestLayout`)
- `resources/js/Components/AudioRecorder.vue`
- `database/factories/SessionParticipantFactory.php`
- `tests/Feature/PublicParticipantTest.php`

**Modificar:**
- `routes/web.php` (rotas públicas)
- `app/Models/Session.php` (relação `participants`, geração de `public_token`)
- `app/Http/Controllers/SessionController.php` (`show`: participantes + link público)
- `resources/js/Pages/Sessions/Show.vue` (compartilhar + participantes ao vivo)

## Testes (Pest, SQLite in-memory, `RefreshDatabase`)

- Página pública abre **sem auth** para token válido.
- Token inválido → **404**.
- `store` cria participante com **só texto**.
- `store` cria participante com **só áudio** (`UploadedFile::fake()`).
- `store` **rejeita** quando faltam texto e áudio.
- `store` **rejeita nome duplicado** na mesma conta (case-insensitive).
- `store` **rejeita** texto com mais de 256 caracteres.
- `store` **rejeita** `audio_duration` > 120.
- `ParticipantSubmitted` é disparado (`Event::fake`).
- `Sessions/Show` do dono inclui **participantes** + **link público** e continua
  **restrito ao dono** (403 para outro usuário).
- Autorização do canal `bill-session.{id}` permanece restrita ao dono.

## Fora de escopo (spec futura — Metade B)

- Transcrição de áudio via OpenAI Whisper (segunda chave de API no `Integration`).
- Interpretação por Claude casando descrições com os itens da nota.
- Cálculo da relação de pagamento (quem deve quanto).
- Disparo em lote do processamento pelo dono.
