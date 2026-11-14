<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a supplier price list, identified as brand / profile / dimensions. */
class SupplierPriceItem extends Model
{
    protected $fillable = [
        'file_id',
        'supplier_id',
        'row_number',
        'designation',
        'supplier_reference',
        'brand_name',
        'brand_id',
        'profile',
        'width',
        'height',
        'diameter',
        'load_index',
        'speed_index',
        'runflat',
        'reinforced',
        'season',
        'price',
        'availability',
        'match_key',
        'product_id',
    ];

    protected $casts = [
        'width' => 'float',
        'height' => 'float',
        'diameter' => 'float',
        'runflat' => 'boolean',
        'reinforced' => 'boolean',
        'price' => 'float',
        'row_number' => 'integer',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(SupplierPriceFile::class, 'file_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
