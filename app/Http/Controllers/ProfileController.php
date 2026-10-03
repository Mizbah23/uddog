<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('organization:id,name'));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'string', Password::min(8)],
        ]);
        unset($data['current_password']);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $request->user()->update($data);

        return response()->json($request->user()->fresh()->load('organization:id,name'));
    }
}
