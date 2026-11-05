<?php

namespace App\Services;

use App\Models\Product;
use App\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageService
{
    public function replace(Product $product, array $keep, array $files): Product
    {
        $disk = Storage::disk('local');
        $created = [];
        $removed = [];
        try {
            $saved = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($product, $keep, $files, $disk, &$created, &$removed): Product {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $current = $locked->ecommerce_images ?? [];
                if (array_diff($keep, $current) || count(array_unique($keep)) !== count($keep) || count($keep) + count($files) > 5) {
                    throw ValidationException::withMessages(['images' => 'Choose up to five images belonging to this product.']);
                }
                $bytes = array_sum(array_map(fn ($path) => $disk->exists($path) ? $disk->size($path) : 0, $keep));
                $bytes += array_sum(array_map(fn ($file) => $file->getSize(), $files));
                if ($bytes > 10 * 1024 * 1024) {
                    throw ValidationException::withMessages(['images' => 'All product images together must be within 10 MB.']);
                }
                if ($files && ! function_exists('imagecreatefromstring')) {
                    throw ValidationException::withMessages(['images' => 'Image processing is unavailable. Ask your administrator to enable PHP GD.']);
                }
                foreach ($files as $file) {
                    $dimensions = getimagesize($file->getPathname());
                    if (! $dimensions || $dimensions[0] * $dimensions[1] > 16000000) {
                        throw ValidationException::withMessages(['images' => 'Choose a photo of 16 megapixels or less.']);
                    }
                    $source = @imagecreatefromstring($file->getContent());
                    if (! $source) {
                        throw ValidationException::withMessages(['images' => 'An image could not be read. Upload a JPEG, PNG or WebP photo.']);
                    }
                    $scale = min(1, 1200 / max(imagesx($source), imagesy($source)));
                    $width = max(1, (int) round(imagesx($source) * $scale));
                    $height = max(1, (int) round(imagesy($source) * $scale));
                    $target = imagecreatetruecolor($width, $height);
                    imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
                    imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                    ob_start();
                    try {
                        imagejpeg($target, null, 85);
                        $encoded = ob_get_contents();
                    } finally {
                        ob_end_clean();
                        unset($target, $source);
                    }
                    $path = 'product-images/'.$locked->organization_id.'/'.$locked->id.'/'.Str::uuid().'.jpg';
                    if (! $encoded || ! $disk->put($path, $encoded)) {
                        throw new \RuntimeException('Could not store the product image. Please try again.');
                    }
                    $created[] = $path;
                }
                $removed = array_values(array_diff($current, $keep));
                $locked->update(['ecommerce_images' => [...$keep, ...$created]]);

                return $locked;
            });
        } catch (Throwable $exception) {
            $disk->delete($created);
            throw $exception;
        }
        $disk->delete($removed);

        return $saved;
    }
}
