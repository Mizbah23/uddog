<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class ResourceUsageController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $account = config('resource_usage.cpanel_account');
        if (! is_string($account) || ! preg_match('/\A[a-z][a-z0-9_]{0,31}\z/i', $account)) {
            return $this->respond(['status' => 'not_configured']);
        }

        return $this->respond(Cache::remember('cpanel-resource-usage:'.$account, 60, function () use ($account): array {
            try {
                $result = Process::timeout(5)->run([
                    '/usr/local/cpanel/bin/cpapi2', '--user='.$account, '--output=json', 'LVEInfo', 'getUsage',
                ]);
            } catch (\RuntimeException) {
                return ['status' => 'unavailable'];
            }

            $payload = data_get(json_decode($result->output(), true), 'cpanelresult');
            if (! $result->successful() || (int) data_get($payload, 'event.result') !== 1) {
                return ['status' => 'unavailable'];
            }

            $samples = collect($payload['data'] ?? [])->filter(fn ($sample) => is_array($sample) && ($sample['ID'] ?? null) === $account)->take(-24);
            if ($samples->isEmpty()) {
                return ['status' => 'unavailable'];
            }

            $columns = ['From', 'To', 'from', 'to', 'mCPU', 'lCPU', 'CPUf', 'mPMem', 'lPMem', 'PMemF', 'mVMem', 'mVmem', 'lVMem', 'VMemF', 'mEP', 'lEP', 'EPf', 'mNproc', 'lNproc', 'NprocF', 'mIO', 'lIO', 'IOf', 'mIOPS', 'lIOPS', 'IOPSf'];

            return [
                'status' => 'ready',
                'fetched_at' => now()->toIso8601String(),
                'samples' => $samples->map(fn (array $sample) => array_intersect_key($sample, array_flip($columns)))->values()->all(),
            ];
        }));
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
