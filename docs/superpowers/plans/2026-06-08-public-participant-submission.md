# Public Participant Submission Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let people without a login open an exclusive share link for a bill, see the receipt photo, and submit their name plus a typed (≤256 chars) or browser-recorded audio (<2 min) description of what they consumed, while the owner watches submissions arrive live over websockets.

**Architecture:** A new unauthenticated route group resolves a bill by a random `public_token` (the ULID is never exposed). Submissions create `session_participants` rows and broadcast a `ParticipantSubmitted` event on the existing private `bill-session.{id}` channel, which the owner's `Sessions/Show` page already subscribes to. No AI runs here — audio is only stored; transcription/interpretation is a future spec.

**Tech Stack:** PHP 8.3 / Laravel 13, Inertia v2 + Vue 3, Tailwind v3, Pest v4 (SQLite in-memory), Laravel Reverb + Echo, MediaRecorder browser API. All `php`/`artisan`/`npm` commands run **inside the `app` container** (`docker compose exec app ...`).

**Spec:** `docs/superpowers/specs/2026-06-08-public-participant-submission-design.md`

---

## File Structure

**Create:**
- `database/migrations/*_add_public_token_to_bill_sessions_table.php` — token column + backfill
- `database/migrations/*_create_session_participants_table.php` — participants table
- `app/Models/SessionParticipant.php` — participant Eloquent model
- `database/factories/SessionParticipantFactory.php` — test factory
- `app/Events/ParticipantSubmitted.php` — broadcast event
- `app/Http/Requests/StorePublicParticipantRequest.php` — validation
- `app/Http/Controllers/PublicSessionController.php` — public show + store
- `resources/js/Layouts/PublicLayout.vue` — minimal layout (no authed navbar)
- `resources/js/Components/AudioRecorder.vue` — MediaRecorder UI
- `resources/js/Pages/Public/Session.vue` — public submission page
- `tests/Feature/PublicParticipantTest.php` — feature tests
- `tests/Feature/ParticipantSubmittedEventTest.php` — broadcast event test

**Modify:**
- `app/Models/Session.php` — `participants()` relation + `public_token` auto-generation + fillable
- `routes/web.php` — public routes outside the `auth` group
- `app/Http/Controllers/SessionController.php` — `show()` passes participants + public link
- `resources/js/Pages/Sessions/Show.vue` — share link section + live participants list

---

## Task 1: Add `public_token` to bill_sessions + auto-generate on the model

**Files:**
- Create: `database/migrations/2026_06_08_000001_add_public_token_to_bill_sessions_table.php`
- Modify: `app/Models/Session.php`
- Test: `tests/Feature/PublicParticipantTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PublicParticipantTest.php`:

```php
<?php

use App\Models\Session;
use App\Models\User;

test('a public token is generated automatically when a session is created', function () {
    $session = Session::factory()->for(User::factory())->create();

    expect($session->public_token)->toBeString()
        ->and(strlen($session->public_token))->toBe(32);
});

test('each session gets a distinct public token', function () {
    $a = Session::factory()->for(User::factory())->create();
    $b = Session::factory()->for(User::factory())->create();

    expect($a->public_token)->not->toBe($b->public_token);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter=PublicParticipantTest`
Expected: FAIL — `public_token` column/attribute does not exist.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_08_000001_add_public_token_to_bill_sessions_table.php`:

```php
<?php

use App\Models\Session;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique()->after('image_path');
        });

        Session::whereNull('public_token')->each(function (Session $session) {
            $session->update(['public_token' => Str::random(32)]);
        });
    }

    public function down(): void
    {
        Schema::table('bill_sessions', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
```

- [ ] **Step 4: Update the Session model**

In `app/Models/Session.php`, add the import, add `public_token` to `$fillable`, and add a `booted()` hook that generates the token.

Add to the imports block (after the existing `use` statements):

```php
use Illuminate\Support\Str;
```

Add `'public_token'` to the `$fillable` array (add it as the last element):

```php
    protected $fillable = [
        'title',
        'image_path',
        'public_token',
        'status',
        'subtotal',
        'service_charge',
        'total',
        'raw_extraction',
        'processed_at',
        'failure_reason',
    ];
```

Add this method inside the class (just below the `casts()` method):

```php
    protected static function booted(): void
    {
        static::creating(function (Session $session): void {
            if (empty($session->public_token)) {
                $session->public_token = Str::random(32);
            }
        });
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec app php artisan test --filter=PublicParticipantTest`
Expected: PASS (both token tests green).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_08_000001_add_public_token_to_bill_sessions_table.php app/Models/Session.php tests/Feature/PublicParticipantTest.php
git commit -m "feat(participants): add auto-generated public_token to sessions"
```

---

## Task 2: Create the `session_participants` table, model, and factory

**Files:**
- Create: `database/migrations/2026_06_08_000002_create_session_participants_table.php`
- Create: `app/Models/SessionParticipant.php`
- Create: `database/factories/SessionParticipantFactory.php`
- Modify: `app/Models/Session.php`
- Test: `tests/Feature/PublicParticipantTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/PublicParticipantTest.php`:

```php
test('a session has many participants ordered oldest first', function () {
    $session = Session::factory()->for(User::factory())->create();

    $first = $session->participants()->create(['name' => 'Ana', 'text' => 'Pizza']);
    $second = $session->participants()->create(['name' => 'Bia', 'text' => 'Suco']);

    $names = $session->fresh()->participants->pluck('name')->all();

    expect($names)->toBe(['Ana', 'Bia'])
        ->and($first->bill_session_id)->toBe($session->id)
        ->and($second->id)->not->toBe($first->id);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter="a session has many participants"`
Expected: FAIL — `session_participants` table / relation missing.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_08_000002_create_session_participants_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_participants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bill_session_id')
                ->constrained('bill_sessions')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('text', 256)->nullable();
            $table->string('audio_path')->nullable();
            $table->unsignedSmallInteger('audio_duration')->nullable();
            $table->timestamps();

            $table->unique(['bill_session_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_participants');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/SessionParticipant.php`:

```php
<?php

namespace App\Models;

use Database\Factories\SessionParticipantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionParticipant extends Model
{
    /** @use HasFactory<SessionParticipantFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'bill_session_id',
        'name',
        'text',
        'audio_path',
        'audio_duration',
    ];

    protected function casts(): array
    {
        return [
            'audio_duration' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'bill_session_id');
    }
}
```

- [ ] **Step 5: Create the factory**

Create `database/factories/SessionParticipantFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionParticipant>
 */
class SessionParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bill_session_id' => Session::factory(),
            'name' => fake()->firstName(),
            'text' => fake()->sentence(4),
            'audio_path' => null,
            'audio_duration' => null,
        ];
    }
}
```

- [ ] **Step 6: Add the relation to the Session model**

In `app/Models/Session.php`, add the `participants()` relation just below the existing `items()` method:

```php
    /**
     * @return HasMany<SessionParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(SessionParticipant::class, 'bill_session_id')
            ->orderBy('created_at');
    }
```

(`HasMany` is already imported in this file — no new import needed.)

- [ ] **Step 7: Run the test to verify it passes**

Run: `docker compose exec app php artisan test --filter="a session has many participants"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_08_000002_create_session_participants_table.php app/Models/SessionParticipant.php database/factories/SessionParticipantFactory.php app/Models/Session.php tests/Feature/PublicParticipantTest.php
git commit -m "feat(participants): add session_participants table, model and factory"
```

---

## Task 3: Public route + page renders for a valid token (no auth)

**Files:**
- Create: `app/Http/Controllers/PublicSessionController.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/Public/Session.vue` (placeholder; fleshed out in Task 7)
- Create: `resources/js/Layouts/PublicLayout.vue` (placeholder; fleshed out in Task 6)
- Test: `tests/Feature/PublicParticipantTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/PublicParticipantTest.php`:

```php
test('the public page opens without auth for a valid token', function () {
    $session = Session::factory()->for(User::factory())->create(['title' => 'Bar do Zé']);

    $response = $this->get("/c/{$session->public_token}");

    $response->assertOk();
});

test('an invalid public token returns 404', function () {
    $response = $this->get('/c/does-not-exist');

    $response->assertNotFound();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter="the public page opens"`
Expected: FAIL — route `/c/{token}` not defined (404 for the valid-token case too).

- [ ] **Step 3: Create the controller (show only for now)**

Create `app/Http/Controllers/PublicSessionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Session;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PublicSessionController extends Controller
{
    public function show(string $token): Response
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        return Inertia::render('Public/Session', [
            'session' => [
                'title' => $session->title,
                'image_url' => Storage::disk('public')->url($session->image_path),
                'token' => $session->public_token,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Register the public routes**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\PublicSessionController;
```

Add these routes **outside** the `auth` middleware group (place them just before `require __DIR__.'/auth.php';`):

```php
Route::get('/c/{token}', [PublicSessionController::class, 'show'])
    ->name('public.sessions.show');

Route::post('/c/{token}/participants', [PublicSessionController::class, 'store'])
    ->name('public.participants.store');
```

- [ ] **Step 5: Create the placeholder layout**

Create `resources/js/Layouts/PublicLayout.vue`:

```vue
<script setup>
import ThemeToggle from '@/Components/ThemeToggle.vue';
</script>

<template>
    <div class="relative flex min-h-screen flex-col items-center bg-canvas pt-6 sm:pt-12">
        <div class="absolute right-4 top-4">
            <ThemeToggle />
        </div>
        <div class="w-full max-w-lg px-4 sm:px-6">
            <slot />
        </div>
    </div>
</template>
```

- [ ] **Step 6: Create the placeholder page**

Create `resources/js/Pages/Public/Session.vue`:

```vue
<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    session: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head :title="session.title" />

    <PublicLayout>
        <h1 class="text-xl font-semibold text-ink">{{ session.title }}</h1>
    </PublicLayout>
</template>
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `docker compose exec app php artisan test --filter="the public page opens"` and `docker compose exec app php artisan test --filter="an invalid public token"`
Expected: PASS for both.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/PublicSessionController.php routes/web.php resources/js/Layouts/PublicLayout.vue resources/js/Pages/Public/Session.vue tests/Feature/PublicParticipantTest.php
git commit -m "feat(participants): add public session route and page shell"
```

---

## Task 4: Validation rules in StorePublicParticipantRequest

**Files:**
- Create: `app/Http/Requests/StorePublicParticipantRequest.php`
- Test: `tests/Feature/PublicParticipantTest.php`

> The controller `store` action does not exist yet — these tests assert validation behavior once Task 5 wires it. To keep TDD honest, this task writes the Request class and a unit-style assertion of its rules; the HTTP-level validation tests live in Task 5.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/PublicParticipantTest.php`:

```php
use App\Http\Requests\StorePublicParticipantRequest;

test('the participant request requires name and one of text or audio', function () {
    $rules = (new StorePublicParticipantRequest)->rules();

    expect($rules)->toHaveKeys(['name', 'text', 'audio', 'audio_duration'])
        ->and($rules['name'])->toContain('required')
        ->and($rules['text'])->toContain('required_without:audio')
        ->and($rules['text'])->toContain('max:256')
        ->and($rules['audio'])->toContain('required_without:text')
        ->and($rules['audio_duration'])->toContain('max:120');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter="the participant request requires"`
Expected: FAIL — class `StorePublicParticipantRequest` not found.

- [ ] **Step 3: Create the Form Request**

Create `app/Http/Requests/StorePublicParticipantRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePublicParticipantRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule()],
            'text' => ['nullable', 'required_without:audio', 'string', 'max:256'],
            'audio' => ['nullable', 'required_without:text', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mp4', 'max:10240'],
            'audio_duration' => ['nullable', 'integer', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome.',
            'text.required_without' => 'Envie um texto ou um áudio.',
            'audio.required_without' => 'Envie um áudio ou um texto.',
            'text.max' => 'O texto pode ter no máximo 256 caracteres.',
            'audio.mimetypes' => 'O áudio deve ser uma gravação válida.',
            'audio_duration.max' => 'O áudio deve ter menos de 2 minutos.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * Case-insensitive uniqueness of the name within this bill session.
     * Enforced in PHP so MySQL (CI collation) and SQLite (CS) behave the same.
     */
    protected function uniqueNameRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $session = Session::where('public_token', $this->route('token'))->first();

            if ($session === null) {
                return;
            }

            $exists = SessionParticipant::query()
                ->where('bill_session_id', $session->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                ->exists();

            if ($exists) {
                $fail('Já existe alguém com esse nome nesta conta.');
            }
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec app php artisan test --filter="the participant request requires"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/StorePublicParticipantRequest.php tests/Feature/PublicParticipantTest.php
git commit -m "feat(participants): add StorePublicParticipantRequest validation"
```

---

## Task 5: Store action persists participants, audio, and broadcasts

**Files:**
- Create: `app/Events/ParticipantSubmitted.php`
- Modify: `app/Http/Controllers/PublicSessionController.php`
- Test: `tests/Feature/PublicParticipantTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PublicParticipantTest.php`:

```php
use App\Events\ParticipantSubmitted;
use App\Models\SessionParticipant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

test('a participant can submit with text only', function () {
    Event::fake();
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Ana',
        'text' => 'Comi uma pizza e tomei um suco',
    ]);

    $response->assertSessionHasNoErrors();
    $participant = SessionParticipant::first();
    expect($participant->name)->toBe('Ana')
        ->and($participant->text)->toBe('Comi uma pizza e tomei um suco')
        ->and($participant->audio_path)->toBeNull();
    Event::assertDispatched(ParticipantSubmitted::class);
});

test('a participant can submit with audio only', function () {
    Event::fake();
    Storage::fake('public');
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Bia',
        'audio' => UploadedFile::fake()->create('voz.webm', 80, 'audio/webm'),
        'audio_duration' => 45,
    ]);

    $response->assertSessionHasNoErrors();
    $participant = SessionParticipant::first();
    expect($participant->name)->toBe('Bia')
        ->and($participant->audio_path)->not->toBeNull()
        ->and($participant->audio_duration)->toBe(45);
    Storage::disk('public')->assertExists($participant->audio_path);
});

test('a participant submission requires text or audio', function () {
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Caio',
    ]);

    $response->assertSessionHasErrors('text');
    expect(SessionParticipant::count())->toBe(0);
});

test('duplicate names are rejected case-insensitively per session', function () {
    $session = Session::factory()->for(User::factory())->create();
    $session->participants()->create(['name' => 'Ana', 'text' => 'Pizza']);

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => '  ana  ',
        'text' => 'Outra coisa',
    ]);

    $response->assertSessionHasErrors('name');
    expect(SessionParticipant::count())->toBe(1);
});

test('text longer than 256 characters is rejected', function () {
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Dora',
        'text' => str_repeat('a', 257),
    ]);

    $response->assertSessionHasErrors('text');
    expect(SessionParticipant::count())->toBe(0);
});

test('audio longer than 120 seconds is rejected', function () {
    Storage::fake('public');
    $session = Session::factory()->for(User::factory())->create();

    $response = $this->post("/c/{$session->public_token}/participants", [
        'name' => 'Eva',
        'audio' => UploadedFile::fake()->create('voz.webm', 80, 'audio/webm'),
        'audio_duration' => 121,
    ]);

    $response->assertSessionHasErrors('audio_duration');
    expect(SessionParticipant::count())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --filter="a participant can submit with text only"`
Expected: FAIL — `store` action missing / event class missing.

- [ ] **Step 3: Create the broadcast event**

Create `app/Events/ParticipantSubmitted.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $participantId,
        public string $name,
        public bool $hasText,
        public bool $hasAudio,
        public ?string $text,
        public ?string $audioUrl,
        public string $createdAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('bill-session.'.$this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'participant.submitted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->participantId,
            'name' => $this->name,
            'has_text' => $this->hasText,
            'has_audio' => $this->hasAudio,
            'text' => $this->text,
            'audio_url' => $this->audioUrl,
            'created_at' => $this->createdAt,
        ];
    }
}
```

- [ ] **Step 4: Add the `store` action to PublicSessionController**

In `app/Http/Controllers/PublicSessionController.php`, add the imports:

```php
use App\Events\ParticipantSubmitted;
use App\Http\Requests\StorePublicParticipantRequest;
use Illuminate\Http\RedirectResponse;
```

Add the `store` method below `show`:

```php
    public function store(StorePublicParticipantRequest $request, string $token): RedirectResponse
    {
        $session = Session::where('public_token', $token)->firstOrFail();

        $audioPath = null;
        if ($request->hasFile('audio')) {
            $audioPath = $request->file('audio')->store('participant-audios', 'public');
        }

        $participant = $session->participants()->create([
            'name' => $request->validated('name'),
            'text' => $request->validated('text'),
            'audio_path' => $audioPath,
            'audio_duration' => $request->validated('audio_duration'),
        ]);

        event(new ParticipantSubmitted(
            sessionId: $session->id,
            participantId: $participant->id,
            name: $participant->name,
            hasText: filled($participant->text),
            hasAudio: filled($participant->audio_path),
            text: $participant->text,
            audioUrl: $audioPath ? Storage::disk('public')->url($audioPath) : null,
            createdAt: $participant->created_at->format('d/m/Y H:i'),
        ));

        return back()->with('success', 'Enviado! Obrigado por participar.');
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=PublicParticipantTest`
Expected: PASS (all submission tests green).

- [ ] **Step 6: Commit**

```bash
git add app/Events/ParticipantSubmitted.php app/Http/Controllers/PublicSessionController.php tests/Feature/PublicParticipantTest.php
git commit -m "feat(participants): persist submissions, store audio and broadcast event"
```

---

## Task 6: ParticipantSubmitted broadcasts on the owner's private channel

**Files:**
- Create: `tests/Feature/ParticipantSubmittedEventTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ParticipantSubmittedEventTest.php`:

```php
<?php

use App\Events\ParticipantSubmitted;
use App\Models\Session;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;

test('the participant event broadcasts on the private session channel', function () {
    $session = Session::factory()->for(User::factory())->create();

    $event = new ParticipantSubmitted(
        sessionId: $session->id,
        participantId: 'part_123',
        name: 'Ana',
        hasText: true,
        hasAudio: false,
        text: 'Pizza',
        audioUrl: null,
        createdAt: '08/06/2026 20:00',
    );

    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(PrivateChannel::class)
        ->and($channel->name)->toBe('private-bill-session.'.$session->id)
        ->and($event->broadcastAs())->toBe('participant.submitted')
        ->and($event->broadcastWith())->toMatchArray([
            'id' => 'part_123',
            'name' => 'Ana',
            'has_text' => true,
            'has_audio' => false,
            'text' => 'Pizza',
            'audio_url' => null,
        ]);
});
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `docker compose exec app php artisan test --filter=ParticipantSubmittedEventTest`
Expected: PASS (the event already exists from Task 5; this locks the channel contract).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ParticipantSubmittedEventTest.php
git commit -m "test(participants): assert ParticipantSubmitted channel contract"
```

---

## Task 7: Owner's Show page exposes the public link + participant list

**Files:**
- Modify: `app/Http/Controllers/SessionController.php`
- Test: `tests/Feature/PublicParticipantTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/PublicParticipantTest.php`:

```php
test('the owner show page includes the public link and participants', function () {
    $user = User::factory()->create();
    $session = Session::factory()->for($user)->create();
    $session->participants()->create(['name' => 'Ana', 'text' => 'Pizza']);

    $this->actingAs($user)
        ->get("/sessions/{$session->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('session.public_url', route('public.sessions.show', $session->public_token))
            ->where('session.participants.0.name', 'Ana')
            ->where('session.participants.0.has_text', true)
            ->where('session.participants.0.has_audio', false)
        );
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec app php artisan test --filter="the owner show page includes"`
Expected: FAIL — `session.public_url` / `session.participants` not present in props.

- [ ] **Step 3: Update SessionController@show**

In `app/Http/Controllers/SessionController.php`, change the eager load line in `show()`:

```php
        $session->load('items');
```

to:

```php
        $session->load(['items', 'participants']);
```

Then add these keys inside the `'session' => [ ... ]` array passed to `Inertia::render('Sessions/Show', ...)` (add them after the existing `'items' => ...` mapping):

```php
                'public_token' => $session->public_token,
                'public_url' => route('public.sessions.show', $session->public_token),
                'participants' => $session->participants->map(fn ($participant) => [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'has_text' => filled($participant->text),
                    'has_audio' => filled($participant->audio_path),
                    'text' => $participant->text,
                    'audio_url' => $participant->audio_path
                        ? Storage::disk('public')->url($participant->audio_path)
                        : null,
                    'created_at' => $participant->created_at->format('d/m/Y H:i'),
                ]),
```

(`Storage` is already imported in this controller — no new import needed.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec app php artisan test --filter="the owner show page includes"`
Expected: PASS.

- [ ] **Step 5: Run the full suite to confirm nothing regressed**

Run: `docker compose exec app php artisan test`
Expected: PASS (all tests green, including the existing `SessionTest` and `ExtractionChannelTest`).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SessionController.php tests/Feature/PublicParticipantTest.php
git commit -m "feat(participants): expose public link and participants on owner show"
```

---

## Task 8: AudioRecorder Vue component (MediaRecorder, ≤120s)

**Files:**
- Create: `resources/js/Components/AudioRecorder.vue`

> No JS unit-test harness exists in this project — verification is a production build plus the manual check in Task 10.

- [ ] **Step 1: Create the component**

Create `resources/js/Components/AudioRecorder.vue`:

```vue
<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';

const MAX_SECONDS = 120;

const emit = defineEmits(['update:blob', 'update:duration']);

const supported = ref(
    typeof navigator !== 'undefined' &&
        !!navigator.mediaDevices &&
        typeof window !== 'undefined' &&
        'MediaRecorder' in window,
);

const recording = ref(false);
const seconds = ref(0);
const audioUrl = ref(null);

let mediaRecorder = null;
let chunks = [];
let stream = null;
let timer = null;

const label = computed(() => {
    const mm = String(Math.floor(seconds.value / 60)).padStart(2, '0');
    const ss = String(seconds.value % 60).padStart(2, '0');
    return `${mm}:${ss}`;
});

const stopTracks = () => {
    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }
};

const clearTimer = () => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
};

const startRecording = async () => {
    if (!supported.value || recording.value) {
        return;
    }

    if (audioUrl.value) {
        URL.revokeObjectURL(audioUrl.value);
        audioUrl.value = null;
    }
    emit('update:blob', null);
    emit('update:duration', 0);

    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        supported.value = false;
        return;
    }

    chunks = [];
    seconds.value = 0;
    mediaRecorder = new MediaRecorder(stream);

    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
            chunks.push(event.data);
        }
    };

    mediaRecorder.onstop = () => {
        clearTimer();
        stopTracks();
        recording.value = false;

        const blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
        audioUrl.value = URL.createObjectURL(blob);
        emit('update:blob', blob);
        emit('update:duration', seconds.value);
    };

    mediaRecorder.start();
    recording.value = true;

    timer = setInterval(() => {
        seconds.value += 1;
        if (seconds.value >= MAX_SECONDS) {
            stopRecording();
        }
    }, 1000);
};

const stopRecording = () => {
    if (mediaRecorder && recording.value) {
        mediaRecorder.stop();
    }
};

const reset = () => {
    if (audioUrl.value) {
        URL.revokeObjectURL(audioUrl.value);
        audioUrl.value = null;
    }
    seconds.value = 0;
    emit('update:blob', null);
    emit('update:duration', 0);
};

onBeforeUnmount(() => {
    clearTimer();
    stopTracks();
    if (audioUrl.value) {
        URL.revokeObjectURL(audioUrl.value);
    }
});
</script>

<template>
    <div class="rounded-md border border-hairline bg-surface-strong p-4">
        <p v-if="!supported" class="text-sm text-muted">
            Seu navegador não permite gravar áudio. Use o campo de texto.
        </p>

        <template v-else>
            <div class="flex items-center gap-3">
                <button
                    v-if="!recording"
                    type="button"
                    class="inline-flex items-center justify-center gap-1.5 rounded-md border border-transparent bg-primary px-[17px] py-2 text-sm font-medium text-on-primary transition-colors duration-150 hover:bg-primary-active focus:outline-none focus:ring-2 focus:ring-primary"
                    @click="startRecording"
                >
                    🎙️ Gravar
                </button>

                <button
                    v-else
                    type="button"
                    class="inline-flex items-center justify-center gap-1.5 rounded-md border border-error bg-surface-card px-[17px] py-2 text-sm font-medium text-error transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-error"
                    @click="stopRecording"
                >
                    ⏹️ Parar
                </button>

                <span class="text-sm tabular-nums text-body">{{ label }} / 02:00</span>
            </div>

            <div v-if="audioUrl" class="mt-3 flex items-center gap-3">
                <audio :src="audioUrl" controls class="h-9 w-full" />
                <button
                    type="button"
                    class="shrink-0 text-sm font-medium text-muted underline hover:text-body"
                    @click="reset"
                >
                    Regravar
                </button>
            </div>
        </template>
    </div>
</template>
```

- [ ] **Step 2: Verify it builds**

Run: `docker compose exec app npm run build`
Expected: Build completes with no errors referencing `AudioRecorder.vue`.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/AudioRecorder.vue
git commit -m "feat(participants): add browser AudioRecorder component"
```

---

## Task 9: Public submission page (Public/Session.vue)

**Files:**
- Modify: `resources/js/Pages/Public/Session.vue`

- [ ] **Step 1: Replace the placeholder page with the full form**

Overwrite `resources/js/Pages/Public/Session.vue`:

```vue
<script setup>
import AudioRecorder from '@/Components/AudioRecorder.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    session: {
        type: Object,
        required: true,
    },
});

const sent = ref(false);

const form = useForm({
    name: '',
    text: '',
    audio: null,
    audio_duration: 0,
});

const remaining = computed(() => 256 - form.text.length);

const canSubmit = computed(
    () => form.name.trim().length > 0 && (form.text.trim().length > 0 || form.audio !== null),
);

const onBlob = (blob) => {
    form.audio = blob;
};

const onDuration = (duration) => {
    form.audio_duration = duration;
};

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            audio: data.audio ?? undefined,
        }))
        .post(route('public.participants.store', props.session.token), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                sent.value = true;
                form.reset();
            },
        });
};
</script>

<template>
    <Head :title="session.title" />

    <PublicLayout>
        <div class="rounded-lg border border-hairline bg-surface-card p-6">
            <h1 class="text-xl font-semibold text-ink">{{ session.title }}</h1>
            <p class="mt-1 text-sm text-muted">Diga o que você consumiu desta conta.</p>

            <div class="mt-4 overflow-hidden rounded-lg border border-hairline">
                <img
                    :src="session.image_url"
                    :alt="`Foto da conta — ${session.title}`"
                    class="block w-full object-contain"
                />
            </div>

            <div
                v-if="sent"
                class="mt-6 rounded-md border border-hairline bg-surface-strong p-4 text-center"
            >
                <p class="text-sm text-body">✓ Enviado! Obrigado por participar.</p>
                <button
                    type="button"
                    class="mt-3 text-sm font-medium text-primary underline"
                    @click="sent = false"
                >
                    Enviar outro nome
                </button>
            </div>

            <form v-else class="mt-6 space-y-5" @submit.prevent="submit">
                <div>
                    <InputLabel for="name" value="Seu nome" />
                    <TextInput
                        id="name"
                        v-model="form.name"
                        type="text"
                        class="mt-1 block w-full"
                        autocomplete="off"
                        required
                    />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="text" value="O que você consumiu (texto)" />
                    <textarea
                        id="text"
                        v-model="form.text"
                        maxlength="256"
                        rows="3"
                        class="mt-1 block w-full rounded-md border border-hairline bg-surface-card px-3 py-2 text-sm text-ink focus:border-primary focus:ring-primary"
                    />
                    <div class="mt-1 flex items-center justify-between">
                        <InputError :message="form.errors.text" />
                        <span class="text-xs text-muted">{{ remaining }} caracteres</span>
                    </div>
                </div>

                <div>
                    <InputLabel value="Ou grave um áudio (até 2 min)" />
                    <div class="mt-1">
                        <AudioRecorder @update:blob="onBlob" @update:duration="onDuration" />
                    </div>
                    <InputError class="mt-2" :message="form.errors.audio" />
                    <InputError class="mt-2" :message="form.errors.audio_duration" />
                </div>

                <PrimaryButton :disabled="!canSubmit || form.processing">Enviar</PrimaryButton>
            </form>
        </div>
    </PublicLayout>
</template>
```

- [ ] **Step 2: Verify it builds**

Run: `docker compose exec app npm run build`
Expected: Build completes with no errors referencing `Public/Session.vue`.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Public/Session.vue
git commit -m "feat(participants): build public submission page"
```

---

## Task 10: Owner Show page — share link + live participants list

**Files:**
- Modify: `resources/js/Pages/Sessions/Show.vue`

- [ ] **Step 1: Add reactive participant state and a public-link copy helper**

In `resources/js/Pages/Sessions/Show.vue`, inside `<script setup>`, after the existing `const copied = ref(false);` line, add:

```js
const participants = ref([...(props.session.participants ?? [])]);
const publicCopied = ref(false);

const copyPublicLink = async () => {
    try {
        await navigator.clipboard.writeText(props.session.public_url);
        publicCopied.value = true;
        setTimeout(() => (publicCopied.value = false), 2000);
    } catch {
        publicCopied.value = false;
    }
};
```

- [ ] **Step 2: Subscribe to participant events on the existing channel**

Replace the existing `subscribe` function and `onMounted` hook with versions that always subscribe and also listen for participants.

Replace:

```js
const subscribe = () => {
    if (!window.Echo || channel) {
        return;
    }
    channel = window.Echo.private(channelName);
    channel.listen('.extraction.updated', () => {
        router.reload({ only: ['session'] });
    });
};

onMounted(() => {
    canShare.value = typeof navigator !== 'undefined' && !!navigator.share;
    if (props.session.status === 'processing') {
        subscribe();
    }
});
```

with:

```js
const subscribe = () => {
    if (!window.Echo || channel) {
        return;
    }
    channel = window.Echo.private(channelName);
    channel.listen('.extraction.updated', () => {
        router.reload({ only: ['session'] });
    });
    channel.listen('.participant.submitted', (payload) => {
        if (!participants.value.some((p) => p.id === payload.id)) {
            participants.value.push(payload);
        }
    });
};

onMounted(() => {
    canShare.value = typeof navigator !== 'undefined' && !!navigator.share;
    subscribe();
});
```

- [ ] **Step 3: Add the public-link and participants sections to the template**

In the template, immediately after the closing `</div>` of the existing "Link da sessão" block (the `<div class="mt-6 rounded-md border border-hairline bg-surface-strong p-4">` that contains the session link), add:

```html
                    <div class="mt-6 rounded-md border border-hairline bg-surface-strong p-4">
                        <span class="text-sm font-medium text-body">Link público (sem login)</span>
                        <p class="mt-1 text-xs text-muted">
                            Compartilhe com quem estava na mesa para enviar nome e o que consumiu.
                        </p>

                        <input
                            type="text"
                            :value="session.public_url"
                            readonly
                            class="mt-2 block w-full rounded-md border border-hairline bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                            @focus="$event.target.select()"
                        />

                        <button
                            type="button"
                            class="mt-3 inline-flex items-center justify-center gap-1.5 rounded-md border border-hairline-strong bg-surface-card px-[17px] py-2 text-sm font-medium text-ink transition-colors duration-150 hover:bg-canvas-soft focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-canvas"
                            @click="copyPublicLink"
                        >
                            {{ publicCopied ? '✓ Copiado!' : '📋 Copiar link público' }}
                        </button>
                    </div>

                    <div class="mt-6">
                        <h3 class="text-sm font-semibold text-ink">
                            Participantes
                            <span class="text-muted">({{ participants.length }})</span>
                        </h3>

                        <p v-if="participants.length === 0" class="mt-2 text-sm text-muted">
                            Ninguém enviou ainda. Os envios aparecem aqui em tempo real.
                        </p>

                        <ul v-else class="mt-3 space-y-2">
                            <li
                                v-for="participant in participants"
                                :key="participant.id"
                                class="rounded-md border border-hairline bg-surface-strong p-3"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-medium text-ink">{{ participant.name }}</span>
                                    <span class="flex gap-1">
                                        <span
                                            v-if="participant.has_text"
                                            class="rounded-full bg-surface-card px-2 py-0.5 text-xs text-body"
                                        >texto</span>
                                        <span
                                            v-if="participant.has_audio"
                                            class="rounded-full bg-surface-card px-2 py-0.5 text-xs text-body"
                                        >áudio</span>
                                    </span>
                                </div>

                                <p v-if="participant.text" class="mt-1 text-sm text-body">
                                    {{ participant.text }}
                                </p>

                                <audio
                                    v-if="participant.audio_url"
                                    :src="participant.audio_url"
                                    controls
                                    class="mt-2 h-9 w-full"
                                />
                            </li>
                        </ul>
                    </div>
```

- [ ] **Step 4: Verify it builds**

Run: `docker compose exec app npm run build`
Expected: Build completes with no errors referencing `Sessions/Show.vue`.

- [ ] **Step 5: Manual end-to-end check**

Bring the stack up (`docker compose up -d`), ensure the `reverb` and `queue` services are running, then:
1. Log in, create a bill, open its Show page — confirm the "Link público" section shows a `/c/<token>` URL and the empty "Participantes" section.
2. Open the `/c/<token>` link in a private/incognito window (no login). Confirm the receipt image renders.
3. Submit a name + text → the owner's Show page list updates **live** (no refresh).
4. Submit a second name + a recorded audio → owner sees it live with an audio player.
5. Re-submit the same name (any case) → rejected with the duplicate-name error.

Expected: All five behaviors hold.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Sessions/Show.vue
git commit -m "feat(participants): show public link and live participant list to owner"
```

---

## Task 11: Final verification

- [ ] **Step 1: Run the full test suite**

Run: `docker compose exec app php artisan test`
Expected: All tests PASS, including `PublicParticipantTest`, `ParticipantSubmittedEventTest`, `SessionTest`, `ExtractionChannelTest`.

- [ ] **Step 2: Run Pint**

Run: `docker compose exec app ./vendor/bin/pint`
Expected: No style violations (files reformatted if needed).

- [ ] **Step 3: Production build**

Run: `docker compose exec app npm run build`
Expected: Clean build.

- [ ] **Step 4: Commit any Pint fixups**

```bash
git add -A
git commit -m "style: apply pint formatting" || echo "nothing to format"
```
