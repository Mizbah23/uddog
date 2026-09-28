<?php

namespace App\Http\Controllers;

use App\Models\DocumentItem;
use App\Models\SupportImpersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class WarrantyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'active', 'expired', 'no_warranty'])],
        ]);
        $search = trim($data['search'] ?? '');

        $items = DocumentItem::query()
            ->whereHas('document', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)->whereIn('type', ['sale', 'resale']);
            })
            ->with([
                'product:id,name,sku,barcode,unit',
                'document:id,organization_id,branch_id,contact_id,number,document_date,type',
                'document.contact:id,name,phone',
                'document.branch:id,name,code',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($matches) use ($like) {
                    $matches->whereHas('product', function ($products) use ($like) {
                        $products->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', 'like', $like);
                    })->orWhereHas('document', function ($documents) use ($like) {
                        $documents->where('number', 'like', $like)->orWhereHas('contact', function ($contacts) use ($like) {
                            $contacts->where('name', 'like', $like)->orWhere('phone', 'like', $like);
                        });
                    });
                });
            })
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(function (DocumentItem $item) {
                [$expiresAt, $status] = $this->warrantyStatus($item);

                return [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'warranty_months' => $item->warranty_months,
                    'warranty_expires_at' => $expiresAt,
                    'warranty_status' => $status,
                    'product' => $item->product,
                    'document' => $item->document,
                ];
            });

        if (($data['status'] ?? 'all') !== 'all') {
            $items = $items->where('warranty_status', $data['status'])->values();
        }

        return response()->json($items->values());
    }

    private function warrantyStatus(DocumentItem $item): array
    {
        if (! $item->warranty_months || ! $item->document?->document_date) {
            return [null, 'no_warranty'];
        }

        $expiresAt = $item->document->document_date->copy()->addMonths($item->warranty_months);

        return [$expiresAt->toDateString(), $expiresAt->endOfDay()->isFuture() ? 'active' : 'expired'];
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
