<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/ConfirmPassword');
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request): RedirectResponse
    {
        Log::info('[Controller][ConfirmablePasswordController][store] Inicio da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            Log::warning('[Controller][ConfirmablePasswordController][store] Confirmação de senha falhou.', [
                'user_id' => $request->user()->id,
            ]);

            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        Log::info('[Controller][ConfirmablePasswordController][store] Senha confirmada. Fim da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
