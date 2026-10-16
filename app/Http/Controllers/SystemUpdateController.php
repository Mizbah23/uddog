<?php

namespace App\Http\Controllers;

use App\Services\MigrationUpdateService;
use App\TenantContext;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SystemUpdateController extends Controller
{
    public function show(MigrationUpdateService $updates, Migrator $migrator, TenantContext $context): JsonResponse
    {
        return response()->json($updates->status($migrator, $context));
    }

    public function store(Request $request, MigrationUpdateService $updates, Migrator $migrator, TenantContext $context): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);

        try {
            $status = $updates->apply($migrator, $context);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The update could not finish. Check the server log or ask the server administrator to run the pending migrations.',
            ], 500);
        }

        return response()->json($status);
    }
}
