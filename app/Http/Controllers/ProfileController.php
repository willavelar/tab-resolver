<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        Log::info('[Controller][ProfileController][update] Inicio da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
            Log::info('[Controller][ProfileController][update] E-mail alterado: verificação reiniciada.', [
                'user_id' => $request->user()->id,
            ]);
        }

        $request->user()->save();

        Log::info('[Controller][ProfileController][update] Fim da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Log::info('[Controller][ProfileController][destroy] Inicio da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('[Controller][ProfileController][destroy] Conta removida. Fim da execusão.', [
            'user_id' => $user->id,
        ]);

        return Redirect::to('/');
    }
}
