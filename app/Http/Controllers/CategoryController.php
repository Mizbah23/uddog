<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\SupportImpersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Category::query()
            ->where('organization_id', $this->organizationId())
            ->withCount('products')
            ->orderBy('name')
            ->get());
    }

    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $data = $this->validated($request, $organizationId);

        return response()->json(Category::create([...$data, 'organization_id' => $organizationId])->loadCount('products'), 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $organizationId = $this->organizationId();
        $saved = Category::query()->where('organization_id', $organizationId)->findOrFail($category);
        $saved->update($this->validated($request, $organizationId, $saved->id));

        return response()->json($saved->fresh()->loadCount('products'));
    }

    public function destroy(int $category): JsonResponse
    {
        $saved = Category::query()->where('organization_id', $this->organizationId())->withCount('products')->findOrFail($category);
        if ($saved->products_count > 0) {
            throw ValidationException::withMessages(['category' => 'Move or remove this category from its products before deleting it.']);
        }
        $saved->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, int $organizationId, ?int $categoryId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories')->where('organization_id', $organizationId)->ignore($categoryId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['required', 'boolean'],
        ]);
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
