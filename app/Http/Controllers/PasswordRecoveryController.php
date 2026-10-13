<?php

namespace App\Http\Controllers;

use App\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordRecoveryController extends Controller
{
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        // Always return the same response to avoid exposing which addresses have accounts.
        Password::sendResetLink($this->scopedCredentials($request, $request->only('email')));

        return response()->json(['message' => 'If an account exists for that email, a password reset link has been sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($this->scopedCredentials($request, $data), function ($user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This password reset link is invalid or has expired. Request a new link and try again.'], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    /** @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function scopedCredentials(Request $request, array $credentials): array
    {
        if (! config('tenancy.enabled')) {
            return $credentials;
        }

        $organization = app(TenantContext::class)->organization();

        if ($organization) {
            $credentials['organization_id'] = $organization->id;
            $credentials['role'] = ['admin', 'manager', 'staff'];
        } else {
            $credentials['role'] = 'superadmin';
        }

        return $credentials;
    }
}
