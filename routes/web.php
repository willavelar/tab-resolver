<?php

// routes/web.php

use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicSessionController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))
        ->name('dashboard');

    Route::get('/sessions/create', [SessionController::class, 'create'])
        ->name('sessions.create');

    Route::post('/sessions', [SessionController::class, 'store'])
        ->name('sessions.store');

    Route::post('/sessions/{session}/extract', [SessionController::class, 'extract'])
        ->name('sessions.extract');

    Route::post('/sessions/{session}/clarify', [SessionController::class, 'clarify'])
        ->name('sessions.clarify');

    Route::get('/sessions/{session}', [SessionController::class, 'show'])
        ->name('sessions.show');

    Route::middleware('can:manage-integrations')->group(function () {
        Route::get('/integrations', [IntegrationController::class, 'edit'])
            ->name('integrations.edit');
        Route::patch('/integrations', [IntegrationController::class, 'update'])
            ->name('integrations.update');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/c/{token}', [PublicSessionController::class, 'show'])
    ->name('public.sessions.show');

Route::post('/c/{token}/participants', [PublicSessionController::class, 'store'])
    ->name('public.participants.store');

require __DIR__.'/auth.php';
