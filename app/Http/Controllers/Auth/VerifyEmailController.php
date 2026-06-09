<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        Log::info('[Controller][VerifyEmailController][__invoke] Inicio da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        if ($request->user()->hasVerifiedEmail()) {
            Log::info('[Controller][VerifyEmailController][__invoke] E-mail já estava verificado. Fim da execusão.', [
                'user_id' => $request->user()->id,
            ]);

            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
            Log::info('[Controller][VerifyEmailController][__invoke] E-mail marcado como verificado.', [
                'user_id' => $request->user()->id,
            ]);
        }

        Log::info('[Controller][VerifyEmailController][__invoke] Fim da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
