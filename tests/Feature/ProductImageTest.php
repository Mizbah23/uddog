<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private function records(): array
    {
        config(['tenancy.base_domain' => 'uddog-soft.com']);
        Storage::fake('local');
        $owner = User::factory()->admin()->create();
        $owner->organization->update(['ecommerce_enabled' => true, 'subdomain' => 'liton']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Photo product', 'unit' => 'pc', 'ecommerce_visible' => true, 'active' => true]);
        $this->actingAs($owner);

        return [$owner, $product];
    }

    public function test_three_photos_are_resized_and_available_in_the_online_store(): void
    {
        [, $product] = $this->records();
        $result = $this->postJson('/api/products/'.$product->id.'/images', ['images' => [
            UploadedFile::fake()->image('wide.jpg', 2400, 1200),
            UploadedFile::fake()->image('portrait.png', 600, 1400),
            UploadedFile::fake()->image('square.jpg', 600, 600),
        ]])->assertOk()->assertJsonCount(3, 'ecommerce_images')->json('ecommerce_images');
        foreach ($result as $path) {
            Storage::disk('local')->assertExists($path);
            [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($path));
            $this->assertLessThanOrEqual(1200, max($width, $height));
            $this->assertSame(IMAGETYPE_JPEG, $type);
        }
        $this->assertSame([1200, 600], array_slice(getimagesizefromstring(Storage::disk('local')->get($result[0])), 0, 2));
        $catalog = $this->getJson('http://liton.uddog-soft.com/online-store/catalog')->assertOk()->assertJsonCount(3, 'products.0.images')->json('products.0');
        $this->get('http://liton.uddog-soft.com'.$catalog['image_url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $product->update(['ecommerce_visible' => false]);
        $this->get('http://liton.uddog-soft.com'.$catalog['image_url'])->assertNotFound();
    }

    public function test_total_upload_limit_and_photo_count_are_enforced_without_writing_files(): void
    {
        [, $product] = $this->records();
        $files = array_map(fn ($n) => UploadedFile::fake()->image($n.'.jpg')->size(4000), range(1, 3));
        $this->postJson('/api/products/'.$product->id.'/images', ['images' => $files])->assertUnprocessable()->assertJsonValidationErrors('images');
        $files = array_map(fn ($n) => UploadedFile::fake()->image($n.'.jpg'), range(1, 6));
        $this->postJson('/api/products/'.$product->id.'/images', ['images' => $files])->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->postJson('/api/products/'.$product->id.'/images', ['images' => [UploadedFile::fake()->create('malicious.svg', 1, 'image/svg+xml')]])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($product->fresh()->ecommerce_images);
    }

    public function test_existing_photos_can_be_kept_or_removed_and_other_company_photos_cannot_be_changed(): void
    {
        [$owner, $product] = $this->records();
        $first = $this->postJson('/api/products/'.$product->id.'/images', ['images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')]])->assertOk()->json('ecommerce_images');
        $this->postJson('/api/products/'.$product->id.'/images', ['keep' => [$first[1]], 'images' => [UploadedFile::fake()->image('three.jpg')]])->assertOk()->assertJsonCount(2, 'ecommerce_images');
        Storage::disk('local')->assertMissing($first[0]);
        Storage::disk('local')->assertExists($first[1]);
        $this->postJson('/api/products/'.$product->id.'/images', ['keep' => ['product-images/other-company/private.jpg']])->assertUnprocessable();
        $other = User::factory()->admin()->create();
        $other->organization->update(['ecommerce_enabled' => true]);
        $this->actingAs($other)->postJson('/api/products/'.$product->id.'/images', [])->assertNotFound();
        $this->get('/api/products/'.$product->id.'/images/'.basename($first[1]))->assertNotFound();
        $this->actingAs($owner)->postJson('/api/products/'.$product->id.'/images', [])->assertOk()->assertJsonCount(0, 'ecommerce_images');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_no_photos_preserves_the_current_fallback_and_uploads_require_online_visibility(): void
    {
        [$owner, $product] = $this->records();
        $this->getJson('http://liton.uddog-soft.com/online-store/catalog')->assertOk()->assertJsonPath('products.0.images', [])->assertJsonPath('products.0.image_url', null);
        $product->update(['ecommerce_image_url' => 'https://example.com/old.jpg']);
        $this->getJson('http://liton.uddog-soft.com/online-store/catalog')->assertOk()->assertJsonPath('products.0.image_url', 'https://example.com/old.jpg');
        $product->update(['ecommerce_visible' => false]);
        $this->postJson('/api/products/'.$product->id.'/images', ['images' => [UploadedFile::fake()->image('one.jpg')]])->assertUnprocessable();
        $owner->organization->update(['ecommerce_enabled' => false]);
        $this->postJson('/api/products/'.$product->id.'/images', [])->assertForbidden();
    }
}
