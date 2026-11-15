<?php

namespace Tests\Feature;

use App\Domain\SupplierPrices\RemoteSpreadsheetFetcher;
use App\Domain\SupplierPrices\SupplierPriceTemplate;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPriceFile;
use App\Models\SupplierPriceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierPriceApiTest extends TestCase
{
    use DatabaseTransactions;

    private Brand $brand;

    private ?User $user = null;

    private Brand $otherBrand;

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view supplier-prices', 'manage supplier-prices', 'create products'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Letters only, so the parser sees one word; explicit codes keep references unique.
        $this->code = 'Q'.strtoupper(Str::random(6));
        $this->code = preg_replace('/[^A-Z]/', 'X', $this->code);
        $this->brand = Brand::query()->create(['name' => 'MARQ'.$this->code, 'code' => $this->code, 'is_active' => true]);
        $this->otherBrand = Brand::query()->create(['name' => 'AUTRE'.$this->code, 'code' => 'W'.substr($this->code, 1), 'is_active' => true]);
        BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'PRIMA SPORT', 'code' => 'PS']);
    }

    private function actingWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user, [], 'web');
        $this->user = $user;

        return $user;
    }

    private function supplier(string $name = 'FOURNISSEUR'): Supplier
    {
        return Supplier::query()->create(['name' => $name.' '.uniqid(), 'user_id' => $this->user?->id ?? User::factory()->create()->id]);
    }

    /** Builds an .xlsx file from rows (first rows can be a title before the header). */
    private function spreadsheet(array $rows): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'tarif').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function upload(array $rows, string $name = 'tarif.xlsx'): UploadedFile
    {
        return new UploadedFile($this->spreadsheet($rows), $name, null, null, true);
    }

    private function standardRows(float $price = 850): array
    {
        return [
            ['TARIF PNEUS NOVEMBRE 2026'],
            [],
            ['Désignation', 'Marque', 'Prix TTC', 'Stock'],
            ['205/55R16 91V PRIMA SPORT XL', $this->brand->name, $price, 'Oui'],
            [$this->otherBrand->name.' 195/65R15 91H ECO DRIVE', '', '1 234,50 DH', 12],
            ['Ligne sans prix', $this->brand->name, '', ''],
        ];
    }

    private function import(Supplier $supplier, array $rows, string $name = 'Tarif'): SupplierPriceFile
    {
        $response = $this->post('/api/supplier-price-files', [
            'supplier_id' => $supplier->id,
            'name' => $name,
            'source_type' => 'upload',
            'file' => $this->upload($rows),
        ], ['Accept' => 'application/json'])->assertCreated();

        return SupplierPriceFile::query()->findOrFail($response->json('id'));
    }

    public function test_search_needs_the_view_permission(): void
    {
        $this->actingWith([]);
        $this->getJson('/api/supplier-prices')->assertForbidden();
        $this->getJson('/api/supplier-price-files')->assertForbidden();
    }

    public function test_viewers_search_but_cannot_manage_files(): void
    {
        $this->actingWith(['view supplier-prices']);
        $this->getJson('/api/supplier-prices')->assertOk();
        $this->getJson('/api/supplier-prices-brands')->assertOk();
        $this->post('/api/supplier-price-files', [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_upload_detects_columns_and_identifies_lines(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $file = $this->import($this->supplier(), $this->standardRows());

        $this->assertSame(2, $file->rows_count);
        $this->assertSame(2, $file->identified_count);
        $this->assertEquals(['designation' => 'A', 'brand' => 'B', 'price' => 'C', 'availability' => 'D'], $file->column_mapping);
        Storage::disk('local')->assertExists($file->stored_path);

        $first = SupplierPriceItem::query()->where('file_id', $file->id)->orderBy('row_number')->first();
        $this->assertSame($this->brand->id, $first->brand_id);
        $this->assertSame('PRIMA SPORT', $first->profile);
        $this->assertEquals([205, 55, 16], [$first->width, $first->height, $first->diameter]);
        $this->assertSame(['91', 'V'], [$first->load_index, $first->speed_index]);
        $this->assertTrue($first->reinforced);
        $this->assertSame(850.0, $first->price);
        $this->assertSame($this->code.'2055516'.'91V-PS', $first->match_key);

        $second = SupplierPriceItem::query()->where('file_id', $file->id)->orderByDesc('row_number')->first();
        $this->assertSame($this->otherBrand->id, $second->brand_id);
        $this->assertSame('ECO DRIVE', $second->profile);
        $this->assertSame(1234.5, $second->price);
        $this->assertSame('12', $second->availability);
    }

    public function test_compare_groups_the_same_tyre_across_suppliers_and_flags_the_best_price(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $cheap = $this->supplier('MOINS CHER');
        $this->import($this->supplier('CHER'), $this->standardRows(900));
        $this->import($cheap, $this->standardRows(820));

        foreach (['2055516', '205/55R16 91V', $this->code.'2055516'] as $search) {
            $groups = $this->getJson('/api/supplier-prices?search='.urlencode($search).'&brand_id='.$this->brand->id)->assertOk()->json('data');
            $this->assertCount(1, $groups, "search {$search}");
            $group = $groups[0];
            $this->assertSame(2, $group['offers_count']);
            $this->assertSame(820.0, (float) $group['best_price']);
            $this->assertSame(80.0, (float) $group['spread']);
            $this->assertSame($cheap->id, $group['offers'][0]['supplier']['id']);
            $this->assertTrue($group['offers'][0]['is_best']);
            $this->assertFalse($group['offers'][1]['is_best']);
            $this->assertSame('205/55R16', $group['dimension']);
        }

        $this->getJson('/api/supplier-prices?search=205/55R16+95W&brand_id='.$this->brand->id)->assertOk()->assertJsonPath('total', 0);

        $brand = collect($this->getJson('/api/supplier-prices-brands')->assertOk()->json('data'))->firstWhere('brand_id', $this->brand->id);
        $this->assertSame(2, $brand['suppliers_count']);
        $this->assertSame(820.0, (float) $brand['min_price']);
    }

    public function test_comparison_filters_on_runflat_xl_and_indexes(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $b = $this->brand->name;
        $this->import($this->supplier(), [
            ['Désignation', 'Marque', 'Prix TTC'],
            ['205/55R16 91V PRIMA SPORT', $b, 900],
            ['205/55R16 91W PRIMA SPORT RFT', $b, 1400],
            ['205/55R16 94V XL PRIMA SPORT', $b, 950],
            ['205/55R16 91H PRIMA SPORT', $b, 800],
        ]);
        $count = fn (string $query) => $this->getJson('/api/supplier-prices?brand_id='.$this->brand->id.'&'.$query)->assertOk()->json('total');

        $this->assertSame(1, $count('runflat=1'));
        $this->assertSame(3, $count('runflat=0'));
        $this->assertSame(1, $count('reinforced=1'));
        $this->assertSame(2, $count('speed_index=V'));
        $this->assertSame(3, $count('speed_index=V&speed_min=1')); // V, V and W
        $this->assertSame(4, $count('speed_index=H&speed_min=1'));
        $this->assertSame(1, $count('load_index=94'));
        $this->assertSame(1, $count('speed_index=V&load_index=91&runflat=0'));
        $this->getJson('/api/supplier-prices?speed_index=V%2B')->assertStatus(422);

        $options = $this->getJson('/api/supplier-prices-filters')->assertOk()->json();
        $speeds = array_column($options['speed_indexes'], 'value');
        // Speed order, not alphabetical: H (210) before V (240) before W (270).
        $this->assertLessThan(array_search('V', $speeds), array_search('H', $speeds));
        $this->assertLessThan(array_search('W', $speeds), array_search('V', $speeds));
        $this->assertSame(240, collect($options['speed_indexes'])->firstWhere('value', 'V')['kmh']);
        $this->assertContains('94', array_column($options['load_indexes'], 'value'));
        $this->assertGreaterThanOrEqual(1, $options['runflat_count']);
    }

    public function test_selling_price_uses_the_default_margin_or_the_files_own(): void
    {
        $admin = $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $admin->assignRole(Role::findOrCreate('Administrator', 'web'));
        $this->getJson('/api/supplier-prices-settings')->assertOk()->assertJsonPath('can_edit_margin', true);
        $this->putJson('/api/supplier-prices-settings', ['default_margin' => 15])->assertOk()->assertJsonPath('default_margin', 15);
        $this->putJson('/api/supplier-prices-settings', ['default_margin' => -1])->assertStatus(422);

        // A "PRIX DE VENTE" column in the file is left aside: the selling price is computed.
        $rows = [
            ['Désignation', 'Marque', 'Prix TTC', 'PRIX DE VENTE'],
            ['205/55R16 91V PRIMA SPORT', $this->brand->name, 1000, 5000],
        ];
        $default = $this->import($this->supplier('DEFAUT'), $rows);
        $this->assertArrayNotHasKey('ignore', $default->column_mapping);
        $this->assertSame(1000.0, (float) $default->items()->value('price'));

        $own = $this->post('/api/supplier-price-files', [
            'supplier_id' => $this->supplier('PROPRE')->id, 'name' => 'Marge 10', 'source_type' => 'upload',
            'margin_percent' => '10', 'file' => $this->upload([$rows[0], [$rows[1][0], $rows[1][1], 900, '']]),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('margin_percent', 10)->json('id');

        $offers = collect($this->getJson('/api/supplier-prices?brand_id='.$this->brand->id)->assertOk()->json('data.0.offers'));
        $this->assertSame(990.0, (float) $offers->firstWhere('price', 900)['sale_price']);   // 900 + 10 %
        $this->assertSame(1150.0, (float) $offers->firstWhere('price', 1000)['sale_price']); // 1000 + 15 % (default)
        $this->assertSame(10.0, (float) $offers->firstWhere('price', 900)['margin_percent']);
        $this->assertSame(150.0, (float) $offers->firstWhere('price', 1000)['margin']);

        // Back to the default margin; no re-import needed.
        $this->post("/api/supplier-price-files/{$own}", ['margin_percent' => ''], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('margin_percent', null);
        $offers = collect($this->getJson('/api/supplier-prices?brand_id='.$this->brand->id)->json('data.0.offers'));
        $this->assertSame(1035.0, (float) $offers->firstWhere('price', 900)['sale_price']);
    }

    public function test_only_an_administrator_sets_margins(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $this->getJson('/api/supplier-prices-settings')->assertOk()->assertJsonPath('can_edit_margin', false);
        $this->putJson('/api/supplier-prices-settings', ['default_margin' => 30])->assertForbidden();

        $file = $this->post('/api/supplier-price-files', [
            'supplier_id' => $this->supplier()->id, 'name' => 'Manager', 'source_type' => 'upload',
            'margin_percent' => '50', 'file' => $this->upload($this->standardRows()),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertNull($file->json('margin_percent'));
    }

    public function test_inactive_files_are_left_out_of_the_comparison(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $file = $this->import($this->supplier(), $this->standardRows());

        $this->post("/api/supplier-price-files/{$file->id}", ['is_active' => '0'], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/supplier-prices?brand_id='.$this->brand->id)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_unknown_columns_return_headers_then_a_manual_mapping_imports(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $supplier = $this->supplier();
        $rows = [
            ['Colonne 1', 'Colonne 2'],
            ['205/55R16 91V PRIMA SPORT '.$this->brand->name, 700],
        ];

        $this->post('/api/supplier-price-files', [
            'supplier_id' => $supplier->id, 'name' => 'Sans en-têtes', 'source_type' => 'upload', 'file' => $this->upload($rows),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('column_mapping')
            ->assertJsonPath('headers.A', 'Colonne 1');
        $this->assertFalse(SupplierPriceFile::query()->where('supplier_id', $supplier->id)->exists());

        $response = $this->post('/api/supplier-price-files', [
            'supplier_id' => $supplier->id, 'name' => 'Sans en-têtes', 'source_type' => 'upload', 'file' => $this->upload($rows),
            'column_mapping' => ['designation' => 'A', 'price' => 'B'],
        ], ['Accept' => 'application/json'])->assertCreated();

        // No header row detected: every row is read, the text one has no price and is skipped.
        $this->assertSame(1, $response->json('rows_count'));
    }

    public function test_changing_the_mapping_reimports_and_delete_removes_the_file(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $file = $this->import($this->supplier(), [
            ['Désignation', 'Prix', 'Prix TTC'],
            ['205/55R16 91V '.$this->brand->name, 100, 120],
        ]);
        $this->assertSame(120.0, (float) $file->items()->value('price'));

        $this->post("/api/supplier-price-files/{$file->id}", ['column_mapping' => ['designation' => 'A', 'price' => 'B']], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(100.0, (float) $file->items()->value('price'));

        $path = $file->fresh()->stored_path;
        $this->deleteJson("/api/supplier-price-files/{$file->id}")->assertNoContent();
        $this->assertDatabaseMissing('supplier_price_files', ['id' => $file->id]);
        $this->assertSame(0, SupplierPriceItem::query()->where('file_id', $file->id)->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_line_creates_the_catalogue_product_once(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices', 'create products']);
        $supplier = $this->supplier();
        $file = $this->import($supplier, $this->standardRows());
        $this->import($this->supplier(), $this->standardRows(870));
        $item = $file->items()->orderBy('row_number')->first();

        $response = $this->postJson("/api/supplier-prices/{$item->id}/product")->assertCreated();
        $this->assertSame($item->match_key, $response->json('product.reference'));
        $this->assertSame($supplier->id, $response->json('supplier_id'));
        $this->assertSame(850.0, (float) $response->json('price'));
        $productId = $response->json('product.id');

        // Every line of that tyre (both suppliers) now points to it.
        $this->assertSame(2, SupplierPriceItem::query()->where('match_key', $item->match_key)->where('product_id', $productId)->count());

        $this->postJson("/api/supplier-prices/{$item->id}/product")->assertOk()->assertJsonPath('created', false)->assertJsonPath('product.id', $productId);
        $this->assertSame(1, Product::query()->where('reference', $item->match_key)->count());
    }

    public function test_reimport_links_lines_to_an_existing_product_by_characteristics(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $product = Product::query()->create(['type' => 'tyre', 'reference' => 'OLD-'.uniqid(), 'brand_id' => $this->brand->id, 'profile' => 'Prima Sport', 'is_active' => true]);
        $product->tyre()->create(['tire_width' => 205, 'tire_height' => 55, 'tire_diameter' => 16, 'tire_load_index' => '91', 'tire_speed_index' => 'V']);

        $file = $this->import($this->supplier(), $this->standardRows());
        $this->assertSame($product->id, $file->items()->orderBy('row_number')->value('product_id'));
    }

    public function test_product_creation_needs_the_create_products_permission(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $file = $this->import($this->supplier(), $this->standardRows());

        $this->postJson('/api/supplier-prices/'.$file->items()->value('id').'/product')->assertForbidden();
    }

    public function test_remote_link_is_downloaded_and_internal_addresses_are_refused(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $this->app->instance(RemoteSpreadsheetFetcher::class, new class extends RemoteSpreadsheetFetcher
        {
            protected function resolve(string $host): array
            {
                return $host === 'intranet.test' ? ['10.0.0.5'] : ['142.250.0.1'];
            }
        });
        Http::fake(['docs.google.com/*' => Http::response(file_get_contents($this->spreadsheet($this->standardRows())))]);
        $supplier = $this->supplier();

        $response = $this->postJson('/api/supplier-price-files', [
            'supplier_id' => $supplier->id,
            'name' => 'Google Sheets',
            'source_type' => 'url',
            'source_url' => 'https://docs.google.com/spreadsheets/d/AbC123_x/edit#gid=42',
            'auto_refresh' => true,
        ])->assertCreated()->assertJsonPath('rows_count', 2)->assertJsonPath('auto_refresh', true);

        Http::assertSent(fn ($request) => $request->url() === 'https://docs.google.com/spreadsheets/d/AbC123_x/export?format=xlsx&gid=42');

        $this->postJson('/api/supplier-price-files/'.$response->json('id').'/refresh')->assertOk()->assertJsonPath('rows_count', 2);

        foreach (['http://127.0.0.1/tarif.xlsx', 'http://intranet.test/tarif.xlsx', 'http://169.254.169.254/latest'] as $url) {
            $this->postJson('/api/supplier-price-files', [
                'supplier_id' => $supplier->id, 'name' => 'Interne', 'source_type' => 'url', 'source_url' => $url,
            ])->assertStatus(422)->assertJsonValidationErrors('source_url');
        }
        $this->assertSame(1, SupplierPriceFile::query()->where('supplier_id', $supplier->id)->count());
    }

    public function test_template_lists_brands_and_a_filled_copy_imports_without_mapping(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);

        $response = $this->get('/api/supplier-price-files/template')->assertOk();
        $this->assertStringContainsString('modele-tarif-fournisseur.xlsx', (string) $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'modele').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);

        // Tarif first, then the brand list and the guide (with examples, never imported).
        $this->assertSame(['Tarif', 'Marques', "Mode d'emploi"], $book->getSheetNames());
        $this->assertSame(SupplierPriceTemplate::HEADERS, $book->getSheetByName('Tarif')->rangeToArray('A1:G1')[0]);
        $brands = array_column($book->getSheetByName('Marques')->toArray(), 0);
        $this->assertContains($this->brand->name, $brands);
        $this->assertStringContainsString('PRIMA SPORT', (string) $book->getSheetByName('Marques')->getCell('B'.(array_search($this->brand->name, $brands) + 1))->getValue());

        // Supplier fills the Tarif sheet; the workbook is saved with another sheet active.
        $book->getSheetByName('Tarif')->fromArray([
            ['REF-1', $this->brand->name, 'PRIMA SPORT', '205/55R16', '91V XL', 'Non', 990],
            ['', $this->otherBrand->name, 'ECO DRIVE', '1956515', '91 H', 'Oui', '640,50'],
        ], null, 'A2', true);
        $book->setActiveSheetIndexByName("Mode d'emploi");
        (new Xlsx($book))->save($path);

        $file = SupplierPriceFile::query()->findOrFail($this->post('/api/supplier-price-files', [
            'supplier_id' => $this->supplier()->id, 'name' => 'Modèle rempli', 'source_type' => 'upload',
            'file' => new UploadedFile($path, 'modele.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id'));

        $this->assertSame(2, $file->rows_count);
        $this->assertSame(2, $file->identified_count);
        $first = $file->items()->orderBy('row_number')->first();
        $this->assertSame('REF-1', $first->supplier_reference);
        $this->assertSame($this->brand->id, $first->brand_id);
        $this->assertSame('PRIMA SPORT', $first->profile);
        $this->assertTrue($first->reinforced);
        $this->assertFalse($first->runflat);
        $this->assertSame(990.0, $first->price);
        $this->assertSame($this->code.'2055516'.'91V-PS', $first->match_key);
        $this->assertEqualsCanonicalizing(['reference' => 'A', 'brand' => 'B', 'profile' => 'C', 'dimension' => 'D', 'indexes' => 'E', 'runflat' => 'F', 'price' => 'G'], $file->column_mapping);

        $second = $file->items()->orderByDesc('row_number')->first();
        $this->assertEquals([195, 65, 15], [$second->width, $second->height, $second->diameter]);
        $this->assertSame(['91', 'H'], [$second->load_index, $second->speed_index]);
        $this->assertTrue($second->runflat);
        $this->assertStringEndsWith('RF-ED', (string) $second->match_key);
        $this->assertSame(640.5, $second->price);

    }

    public function test_remote_link_returning_a_web_page_is_refused(): void
    {
        $this->actingWith(['view supplier-prices', 'manage supplier-prices']);
        $this->app->instance(RemoteSpreadsheetFetcher::class, new class extends RemoteSpreadsheetFetcher
        {
            protected function resolve(string $host): array
            {
                return ['142.250.0.1'];
            }
        });
        Http::fake(['*' => Http::response('<!DOCTYPE html><html><body>Connexion</body></html>')]);

        $this->postJson('/api/supplier-price-files', [
            'supplier_id' => $this->supplier()->id, 'name' => 'Privé', 'source_type' => 'url', 'source_url' => 'https://example.org/tarif',
        ])->assertStatus(422)->assertJsonValidationErrors('source_url');
    }

    public function test_the_selling_price_is_rounded_up_to_0_or_5(): void
    {
        $service = app(\App\Domain\SupplierPrices\SupplierPriceSearchService::class);

        $this->assertSame(['sale_price' => 770.0, 'margin' => 140.0, 'margin_percent' => 22.0], $service->salePrice(630, 22)); // 768.60
        $this->assertSame(765.0, $service->salePrice(700, 8.74)['sale_price']); // 761.18
        $this->assertSame(765.0, $service->salePrice(765, 0)['sale_price']);     // already ends in 5
        $this->assertSame(990.0, $service->salePrice(900, 10)['sale_price']);    // exact: no rounding up
        $this->assertSame(630.0, $service->salePrice(630, 0)['sale_price']);
    }
}
