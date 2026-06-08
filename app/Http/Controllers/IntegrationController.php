<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateIntegrationRequest;
use App\Models\Integration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function edit(): Response
    {
        $integration = Integration::current();
        $apiKey = $integration->api_key;

        return Inertia::render('Integrations/Edit', [
            'model' => $integration->model,
            'has_api_key' => filled($apiKey),
            'api_key_preview' => filled($apiKey)
                ? '••••••••'.substr($apiKey, -4)
                : null,
            'status' => session('status'),
        ]);
    }

    public function update(UpdateIntegrationRequest $request): RedirectResponse
    {
        $integration = Integration::current();
        $integration->model = $request->validated('model');

        if ($request->filled('api_key')) {
            $integration->api_key = $request->validated('api_key');
        }

        $integration->save();

        return Redirect::route('integrations.edit')->with('status', 'integration-updated');
    }
}
