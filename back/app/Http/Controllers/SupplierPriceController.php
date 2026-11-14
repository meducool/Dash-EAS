<?php

namespace App\Http\Controllers;

use App\Domain\SupplierPrices\SupplierPriceFileService;
use App\Domain\SupplierPrices\SupplierPriceImporter;
use App\Domain\SupplierPrices\SupplierPriceSearchService;
use App\Domain\SupplierPrices\SupplierPriceTemplate;
use App\Models\SupplierPriceFile;
use App\Models\SupplierPriceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Tarifs fournisseurs: price lists (files / links), search & comparison, brands. */
class SupplierPriceController extends Controller
{
    public function __construct(
        private SupplierPriceFileService $files,
        private SupplierPriceSearchService $search,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'brand_id' => ['nullable', 'integer'],
            'brand_name' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer'],
            'file_id' => ['nullable', 'integer'],
            'runflat' => ['nullable', 'in:0,1'],
            'reinforced' => ['nullable', 'in:0,1'],
            'speed_index' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,2}$/'],
            'speed_min' => ['nullable', 'in:0,1'],
            'load_index' => ['nullable', 'string', 'regex:/^\d{2,3}$/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->search->compare($filters));
    }

    /** Values present in the active price lists (speed / load indexes, runflat, XL), for the filters. */
    public function filters(): JsonResponse
    {
        return response()->json($this->search->filterOptions());
    }

    /** Default margin applied to the price lists (selling price = Prix TTC × (1 + %)). */
    public function settings(Request $request): JsonResponse
    {
        return response()->json([
            'default_margin' => $this->search->defaultMargin(),
            'can_edit_margin' => $this->isAdmin($request),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Seul un administrateur peut changer la marge.');
        $validated = $request->validate(['default_margin' => ['required', 'numeric', 'min:0', 'max:500']]);

        return response()->json([
            'default_margin' => $this->search->setDefaultMargin((float) $validated['default_margin']),
            'can_edit_margin' => true,
        ]);
    }

    public function brands(): JsonResponse
    {
        return response()->json($this->search->brands());
    }

    public function createProduct(Request $request, SupplierPriceItem $item): JsonResponse
    {
        $result = $this->search->productFor($item, $request->user()?->id);

        return response()->json([
            'product' => $result['product'],
            'created' => $result['created'],
            'supplier_id' => $item->supplier_id,
            'price' => (float) $item->price,
        ], $result['created'] ? 201 : 200);
    }

    public function files(): JsonResponse
    {
        return response()->json([
            'data' => $this->files->list(),
            'fields' => SupplierPriceImporter::FIELDS,
        ]);
    }

    /** Standard price-list template (all brands), to fill in Google Sheets or Excel. */
    public function template(SupplierPriceTemplate $template): StreamedResponse
    {
        $book = $template->build();

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, 'modele-tarif-fournisseur.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function showFile(SupplierPriceFile $file): JsonResponse
    {
        return response()->json($file->load('supplier:id,name'));
    }

    public function storeFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'name' => ['required', 'string', 'max:150'],
            'source_type' => ['required', Rule::in(['upload', 'url'])],
            'file' => ['required_if:source_type,upload', 'nullable', 'file', 'mimes:xlsx,xls,csv,ods,txt', 'max:15360'],
            'source_url' => ['required_if:source_type,url', 'nullable', 'url:http,https', 'max:2048'],
            'auto_refresh' => ['nullable', 'boolean'],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:500'],
            ...$this->mappingRules(),
        ]);

        $file = $this->files->create($this->onlyAdminSetsMargin($request, $validated), $request->file('file'), $request->user()?->id);

        return response()->json($file, 201);
    }

    public function updateFile(Request $request, SupplierPriceFile $file): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'name' => ['sometimes', 'string', 'max:150'],
            'file' => ['nullable', 'file', 'mimes:xlsx,xls,csv,ods,txt', 'max:15360'],
            'source_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'auto_refresh' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'margin_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            ...$this->mappingRules(),
        ]);

        return response()->json($this->files->update($file, $this->onlyAdminSetsMargin($request, $validated), $request->file('file')));
    }

    public function refreshFile(SupplierPriceFile $file): JsonResponse
    {
        return response()->json($this->files->refresh($file));
    }

    public function destroyFile(SupplierPriceFile $file): JsonResponse
    {
        $this->files->delete($file);

        return response()->json(null, 204);
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->hasRole('Administrator');
    }

    /**
     * The margin (hence the selling price) is the administrator's call: dropped for anyone else.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function onlyAdminSetsMargin(Request $request, array $validated): array
    {
        if (! $this->isAdmin($request)) {
            unset($validated['margin_percent']);
        }

        return $validated;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function mappingRules(): array
    {
        $rules = ['column_mapping' => ['sometimes', 'nullable', 'array']];
        foreach (SupplierPriceImporter::FIELDS as $field) {
            $rules["column_mapping.{$field}"] = ['nullable', 'string', 'regex:/^[A-Za-z]{1,3}$/'];
        }

        return $rules;
    }
}
