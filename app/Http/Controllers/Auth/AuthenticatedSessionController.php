<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        Log::info('[Controller][AuthenticatedSessionController][store] Inicio da execusão.', [
            'email' => $request->input('email'),
        ]);

        $request->authenticate();

        $request->session()->regenerate();

        Log::info('[Controller][AuthenticatedSessionController][store] Usuário autenticado. Fim da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Log::info('[Controller][AuthenticatedSessionController][destroy] Inicio da execusão.', [
            'user_id' => $request->user()?->id,
        ]);

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        Log::info('[Controller][AuthenticatedSessionController][destroy] Sessão encerrada. Fim da execusão.');

        return redirect('/');
    }
}
