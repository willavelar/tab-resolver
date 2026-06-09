<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->logName = 'test-'.uniqid().'.log';
    $this->logPath = storage_path('logs/'.$this->logName);
    File::put($this->logPath, "line one\nline two\n");
});

afterEach(function () {
    File::delete($this->logPath);
});

it('redirects guests away from the logs page', function () {
    $this->get('/logs')->assertRedirect('/login');
});

it('forbids non-admin users from listing logs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/logs')->assertForbidden();
});

it('forbids non-admin users from reading a log file', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/logs/'.$this->logName)
        ->assertForbidden();
});

it('lists the log files for admins', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/logs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Logs/Index')
            ->where('files', fn ($files) => collect($files)
                ->contains(fn ($file) => $file['name'] === $this->logName))
        );
});

it('returns the contents of a log file for admins', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->getJson('/logs/'.$this->logName)
        ->assertOk()
        ->assertJson([
            'name' => $this->logName,
            'content' => "line one\nline two\n",
        ]);
});

it('blocks path traversal to files outside storage/logs', function () {
    $user = User::factory()->admin()->create();

    // A name that is not one of the real .log files can never be served.
    $this->actingAs($user)
        ->get('/logs/'.urlencode('../../.env'))
        ->assertNotFound();
});

it('does not serve non-log files even by exact name', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->getJson('/logs/.gitignore')
        ->assertNotFound();
});
