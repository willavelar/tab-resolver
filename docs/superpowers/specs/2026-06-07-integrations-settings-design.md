# Integrações — Configuração de IA (Prism/Anthropic) — Design

**Data:** 2026-06-07
**Status:** Aprovado para implementação

## Objetivo

Adicionar um menu **Integrações** (ao lado de Dashboard no topo) com um formulário
para configurar a integração com a IA (Prism + Anthropic). A chave da API é
armazenada **criptografada** no banco — ilegível ao acessar o banco diretamente,
acessível apenas internamente para a integração externa. Depois de salva, a chave
aparece **mascarada** (apenas os últimos 4 caracteres) e nunca trafega em texto puro
para o frontend.

## Decisões

- **Escopo:** Global do app — uma única configuração compartilhada (substitui o
  `.env` em runtime). Qualquer usuário autenticado vê/edita o mesmo registro.
- **Campos:** Chave da API (protegida/criptografada) + Modelo (texto livre).
  URL e versão continuam vindo do `.env`/config.
- **Exibição pós-save:** Mascarada — preview com os últimos 4 caracteres
  (ex.: `••••••••1234`) + badge "Configurado ✓". Campo da chave começa vazio:
  digitar um valor novo substitui; deixar em branco mantém a chave atual.

## Abordagem escolhida

Modelo singleton `Integration` + cast nativo `encrypted` do Laravel + override de
`config()` em runtime no extractor. Usa apenas recursos nativos do Laravel; o Prism
não precisa de alteração.

Alternativas descartadas:
- Tabela genérica chave-valor `settings` — over-engineering para um provider só.
- Reescrever o `.env` — ruim em containers (bind-mount, race conditions, exige
  reload de config).

## Arquitetura

### Dados

Migration `create_integrations_table` (gerar via `php artisan make:migration`):

| Coluna     | Tipo                          | Notas                                  |
|------------|-------------------------------|----------------------------------------|
| `id`       | `ulid` primary                | Padrão do projeto (sem auto-increment) |
| `provider` | `string`, default `anthropic` | Identifica o provider                  |
| `api_key`  | `text`, nullable              | Cast `encrypted` (ciphertext no banco) |
| `model`    | `string`, nullable            | Modelo do provider                     |
| timestamps | —                             | created_at / updated_at                |

Model `App\Models\Integration`:
- `$casts = ['api_key' => 'encrypted']` — criptografa via `APP_KEY`.
- Usa ULID (`HasUlids` trait, conforme convenção).
- Helper estático `Integration::current(): Integration` →
  `firstOrNew(['provider' => 'anthropic'])` (padrão singleton global).

### Backend

Rotas no grupo `auth` em `routes/web.php`:
- `GET /integrations` → `IntegrationController@edit`, nome `integrations.edit`.
- `PATCH /integrations` → `IntegrationController@update`, nome `integrations.update`.

`App\Http\Controllers\IntegrationController`:
- `edit`: `Inertia::render('Integrations/Edit', [...])` passando **apenas**:
  - `model` (string|null)
  - `has_api_key` (bool) — se há chave configurada
  - `api_key_preview` (string|null) — `••••••••` + últimos 4 da chave; `null` se vazia
  - **A chave real nunca vai para o frontend.**
- `update`: aplica `UpdateIntegrationRequest`, salva no registro `current()`,
  redireciona de volta com flash de sucesso.

`App\Http\Requests\UpdateIntegrationRequest`:
- `model` → `required|string`
- `api_key` → `nullable|string`
- Regra de manutenção: se `api_key` vier vazio/ausente, **mantém** a chave atual
  (não sobrescreve); se vier preenchido, substitui. Implementado no controller
  (só seta `api_key` quando `filled('api_key')`).

### Integração em runtime

Em `App\Services\Receipt\PrismReceiptExtractor::extract`:
1. `$integration = Integration::current();`
2. Se `$integration->api_key` presente:
   - `config(['prism.providers.anthropic.api_key' => $integration->api_key]);`
   - `$model = $integration->model ?: config('services.anthropic.receipt_model');`
3. Senão (sem registro/sem chave): fallback ao comportamento atual
   (`config('services.anthropic.receipt_model')` e chave do `.env`).
4. `->using(Provider::Anthropic, $model)`.

Isso mantém compatibilidade quando nenhuma integração foi configurada via UI.

### Frontend

`AuthenticatedLayout.vue`:
- NavLink desktop "Integrações" ao lado de Dashboard:
  `:href="route('integrations.edit')"` / `:active="route().current('integrations.edit')"`.
- ResponsiveNavLink equivalente no menu mobile.

`resources/js/Pages/Integrations/Edit.vue`:
- `defineProps({ model, has_api_key, api_key_preview })`.
- `useForm({ model, api_key: '' })`.
- Primitivas do Breeze: `InputLabel`, `TextInput`, `InputError`, `PrimaryButton`.
- Campo da chave: `type="password"`, começa vazio, `placeholder` com o
  `api_key_preview` quando existir; badge "Configurado ✓" quando `has_api_key`.
- Campo modelo: pré-preenchido com `model`.
- Submit via `form.patch(route('integrations.update'))`.
- Cópia em **PT-BR**.

## Tratamento de erros

- Validação via `UpdateIntegrationRequest` (mensagens PT-BR padrão do Laravel),
  exibidas com `InputError`.
- Sem chave configurada + disparo de extração: mantém o fluxo de erro atual do job
  (sem mudança de comportamento).

## Testes (Pest, SQLite in-memory)

Feature (`tests/Feature/IntegrationTest.php`):
1. `integrations.edit` renderiza para usuário autenticado; redireciona visitante
   para login.
2. `update` grava a chave **criptografada** — valor cru na coluna ≠ plaintext, e
   `Crypt::decrypt(valor_cru)` retorna o plaintext; modelo persistido.
3. `update` com `api_key` em branco mantém a chave anterior.
4. `model` é obrigatório (erro de validação quando ausente).
5. Props do Inertia **não vazam** a chave — só `api_key_preview` mascarado.

Unit/Feature do extractor:
6. Com `Integration` configurada, o extractor passa a usar a chave/modelo do banco
   (verificável via override de `config` — assertar que `config('prism.providers.anthropic.api_key')`
   reflete o valor do banco após o caminho de setup, sem chamada real à API).

## Fora de escopo (YAGNI)

- Múltiplos providers / múltiplas integrações.
- Configuração por usuário.
- Edição de URL e versão da API via UI.
- Teste de conexão ("testar chave") — pode ser um follow-up.
