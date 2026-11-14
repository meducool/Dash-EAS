<?php

namespace Tests\Feature;

use App\Domain\Products\ProductImageService;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Photos of an article: /api/products/{product}/images, /api/product-images/{image}. */
class ProductImageApiTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view products', 'edit products', 'delete products'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->product = Product::query()->create(['type' => 'part', 'reference' => 'IMG-'.uniqid(), 'is_active' => true]);
    }

    private function actingWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Real minimal image (no GD in the container): signature bytes of the format. */
    private function img(string $name): File
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $content = match ($ext) {
            'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='),
            'webp' => 'RIFF'.pack('V', 26).'WEBPVP8 '.pack('V', 14).str_repeat("\0", 14),
            default => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\0", 64)."\xFF\xD9",
        };

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/products/'.$this->product->id.'/images'.$suffix;
    }

    public function test_upload_requires_edit_permission(): void
    {
        $this->actingWith('view products');

        $this->post($this->url(), ['images' => [$this->img('a.jpg')]], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_upload_stores_photos_in_order_and_lists_them(): void
    {
        $user = $this->actingWith('view products', 'edit products');

        $response = $this->post($this->url(), [
            'images' => [$this->img('face.jpg'), $this->img('profil.png')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('max', ProductImageService::MAX_IMAGES)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.original_name', 'face.jpg')
            ->assertJsonPath('data.0.position', 0)
            ->assertJsonPath('data.1.position', 1)
            ->assertJsonMissingPath('data.0.path');

        $image = ProductImage::query()->where('product_id', $this->product->id)->orderBy('position')->first();
        Storage::disk('public')->assertExists($image->path);
        $this->assertStringStartsWith('products/'.$this->product->id.'/', $image->path);
        $this->assertSame($user->id, $image->created_by);
        $this->assertStringEndsWith('/storage/'.$image->path, $response->json('data.0.url'));

        // A second drop goes after the existing photos.
        $this->post($this->url(), ['images' => [$this->img('dos.webp')]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.2.original_name', 'dos.webp')
            ->assertJsonPath('data.2.position', 2);

        $this->getJson($this->url())->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_upload_rejects_non_images_svg_and_large_files(): void
    {
        $this->actingWith('edit products');

        foreach ([
            UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            $this->img('big.jpg')->size(ProductImageService::MAX_KB + 1),
        ] as $file) {
            $this->post($this->url(), ['images' => [$file]], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('images.0');
        }

        $this->assertSame(0, ProductImage::query()->where('product_id', $this->product->id)->count());
    }

    public function test_upload_refuses_more_than_the_maximum(): void
    {
        $this->actingWith('edit products');
        $files = fn (int $n) => array_map(fn ($i) => $this->img("p{$i}.jpg"), range(1, $n));

        $this->post($this->url(), ['images' => $files(ProductImageService::MAX_IMAGES - 1)], ['Accept' => 'application/json'])->assertCreated();

        $this->post($this->url(), ['images' => $files(2)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');

        $this->assertSame(ProductImageService::MAX_IMAGES - 1, ProductImage::query()->where('product_id', $this->product->id)->count());
        $this->assertCount(ProductImageService::MAX_IMAGES - 1, Storage::disk('public')->allFiles('products/'.$this->product->id));
    }

    public function test_reorder_sets_the_main_photo(): void
    {
        $this->actingWith('edit products');
        $ids = collect($this->post($this->url(), [
            'images' => [$this->img('a.jpg'), $this->img('b.jpg'), $this->img('c.jpg')],
        ], ['Accept' => 'application/json'])->json('data'))->pluck('id')->all();

        $this->putJson($this->url('/order'), ['ids' => [$ids[2], $ids[0], $ids[1]]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $ids[2])
            ->assertJsonPath('data.1.id', $ids[0])
            ->assertJsonPath('data.2.id', $ids[1]);

        // Missing, duplicated or foreign ids are refused.
        $other = Product::query()->create(['type' => 'part', 'reference' => 'IMG2-'.uniqid(), 'is_active' => true]);
        $foreign = ProductImage::query()->create(['product_id' => $other->id, 'path' => 'products/x.jpg', 'position' => 0]);
        foreach ([[$ids[0], $ids[1]], [$ids[0], $ids[0], $ids[1]], [$ids[0], $ids[1], $foreign->id]] as $bad) {
            $this->putJson($this->url('/order'), ['ids' => $bad])->assertStatus(422)->assertJsonValidationErrors('ids');
        }
    }

    public function test_delete_removes_the_file_and_renumbers(): void
    {
        $this->actingWith('edit products');
        $data = $this->post($this->url(), [
            'images' => [$this->img('a.jpg'), $this->img('b.jpg')],
        ], ['Accept' => 'application/json'])->json('data');
        $first = ProductImage::query()->findOrFail($data[0]['id']);

        $this->deleteJson('/api/product-images/'.$first->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $data[1]['id'])
            ->assertJsonPath('data.0.position', 0);

        Storage::disk('public')->assertMissing($first->path);
        $this->assertDatabaseMissing('product_images', ['id' => $first->id]);
    }

    public function test_deleting_the_product_removes_its_photos(): void
    {
        $this->actingWith('edit products', 'delete products');
        $this->post($this->url(), ['images' => [$this->img('a.jpg')]], ['Accept' => 'application/json'])->assertCreated();
        $path = ProductImage::query()->where('product_id', $this->product->id)->value('path');

        $this->deleteJson('/api/products/'.$this->product->id)->assertNoContent();

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('product_images', ['product_id' => $this->product->id]);
    }

    public function test_listing_requires_view_permission(): void
    {
        $this->actingWith('delete products');

        $this->getJson($this->url())->assertForbidden();
    }
}
