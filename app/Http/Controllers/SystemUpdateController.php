<?php

namespace App\Http\Controllers;

use App\Services\MigrationUpdateService;
use App\TenantContext;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
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
                'message' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The update could not finish. Check the newest server log entry for details.',
            ], 500);
        }

        return response()->json($status);
    }
}
