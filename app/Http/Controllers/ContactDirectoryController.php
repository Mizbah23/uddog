<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Contact;
use App\Models\Document;
use App\Models\SupportImpersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContactDirectoryController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        return $this->directory($request, 'customer');
    }

    public function suppliers(Request $request): JsonResponse
    {
        return $this->directory($request, 'supplier');
    }

    private function directory(Request $request, string $type): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchIds = app(BranchAccess::class)->ids(Auth::user(), $organizationId);
        $contacts = Contact::query()
            ->where('organization_id', $organizationId)
            ->whereIn('type', [$type, 'both'])
            ->orderBy('name')
            ->get();
        $metrics = $this->metrics($organizationId, $type, $branchIds);

        return response()->json($contacts->map(function (Contact $contact) use ($metrics, $type) {
            $metric = $metrics[$contact->id] ?? ['gross' => 0, 'returns' => 0, 'paid' => 0];
            $total = $metric['gross'] - $metric['returns'];
            $paid = $metric['paid'];

            return [
                ...$contact->toArray(),
                $type === 'customer' ? 'sales_total' : 'purchases_total' => number_format($total, 2, '.', ''),
                'paid_total' => number_format($paid, 2, '.', ''),
                'balance_total' => number_format($total - $paid, 2, '.', ''),
            ];
        })->values());
    }

    private function metrics(int $organizationId, string $type, array $branchIds): array
    {
        $positiveTypes = $type === 'customer' ? ['sale', 'resale'] : ['purchase'];
        $returnType = $type === 'customer' ? 'sale_return' : 'purchase_return';
        $metrics = [];

        Document::query()
            ->selectRaw('contact_id, type, SUM(total) as total, SUM(amount_paid) as paid')
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', $branchIds)
            ->whereIn('type', [...$positiveTypes, $returnType])
            ->groupBy('contact_id', 'type')
            ->get()
            ->each(function (Document $document) use (&$metrics, $positiveTypes) {
                $metrics[$document->contact_id] ??= ['gross' => 0, 'returns' => 0, 'paid' => 0];
                if (in_array($document->type, $positiveTypes, true)) {
                    $metrics[$document->contact_id]['gross'] += (float) $document->total;
                    $metrics[$document->contact_id]['paid'] += (float) $document->paid;
                } else {
                    $metrics[$document->contact_id]['returns'] += (float) $document->total;
                }
            });

        return $metrics;
    }

    private function organizationId(): int
    {
        if (Auth::user()?->isSuperadmin() && ! SupportImpersonation::activeFor(request())) {
            abort(403, 'Open a company through View as owner before using its workspace.');
        }
        abort_unless(Auth::user()?->organization_id, 403, 'Select a client account before using the inventory workspace.');

        return Auth::user()->organization_id;
    }
}
