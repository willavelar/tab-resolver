<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        Log::info('[Controller][EmailVerificationNotificationController][store] Inicio da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        if ($request->user()->hasVerifiedEmail()) {
            Log::info('[Controller][EmailVerificationNotificationController][store] E-mail já verificado, nada a enviar. Fim da execusão.', [
                'user_id' => $request->user()->id,
            ]);

            return redirect()->intended(route('dashboard', absolute: false));
        }

        $request->user()->sendEmailVerificationNotification();

        Log::info('[Controller][EmailVerificationNotificationController][store] Notificação de verificação enviada. Fim da execusão.', [
            'user_id' => $request->user()->id,
        ]);

        return back()->with('status', 'verification-link-sent');
    }
}
