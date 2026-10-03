<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\SupportImpersonation;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SupportImpersonationController extends Controller
{
    public function store(Request $request, Organization $client): JsonResponse
    {
        $owner = $client->users()
            ->where('role', UserRole::Admin->value)
            ->where('active', true)
            ->where('access_paused', false)
            ->oldest()
            ->first();

        if (! $owner) {
            throw ValidationException::withMessages([
                'client' => 'This company does not have an active owner administrator to view.',
            ]);
        }

        $impersonation = SupportImpersonation::create([
            'impersonator_id' => $request->user()->id,
            'impersonated_user_id' => $owner->id,
            'organization_id' => $client->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $request->session()->put(SupportImpersonation::SESSION_KEY, $impersonation->id);
        Auth::login($owner);
        $request->session()->regenerate();

        return response()->json([
            'user' => $owner->load('organization'),
            'message' => "You are now viewing {$client->name} as {$owner->name}.",
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $impersonation = SupportImpersonation::activeFor($request);
        abort_unless($impersonation, 403, 'There is no active support session.');

        $impersonator = $impersonation->impersonator;
        $impersonation->update(['ended_at' => now()]);
        $request->session()->forget(SupportImpersonation::SESSION_KEY);
        Auth::login($impersonator);
        $request->session()->regenerate();

        return response()->json([
            'user' => $impersonator->load('organization'),
            'message' => 'You have returned to the superadmin workspace.',
        ]);
    }
}
