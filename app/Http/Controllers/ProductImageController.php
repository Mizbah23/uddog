<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImageController extends Controller
{
    public function store(Request $request, int $product, ProductImageService $images): JsonResponse
    {
        $organization = $request->user()->organization()->firstOrFail();
        abort_unless($organization->ecommerce_enabled, 403);
        $record = Product::query()->where('organization_id', $organization->id)->findOrFail($product);
        abort_unless($record->ecommerce_visible, 422, 'Enable online-store visibility before uploading images.');
        $data = $request->validate([
            'keep' => ['sometimes', 'array', 'max:5'], 'keep.*' => ['required', 'string', 'max:255', 'distinct'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=6000,max_height=6000'],
        ]);

        return response()->json($images->replace($record, $data['keep'] ?? [], $request->file('images', [])));
    }

    public function show(Request $request, int $product, string $image): StreamedResponse
    {
        $record = Product::query()->where('organization_id', $request->user()->organization_id)->findOrFail($product);

        return $this->image($record, $image);
    }

    public function image(Product $product, string $image): StreamedResponse
    {
        $path = collect($product->ecommerce_images ?? [])->first(fn ($path) => basename($path) === $image);
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff']);
    }
}
