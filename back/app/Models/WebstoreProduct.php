<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A product of the WooCommerce shop as last read, matched with the catalogue by SKU. */
class WebstoreProduct extends Model
{
    public const MATCHED = 'matched';

    public const SITE_ONLY = 'site_only';

    public const NO_SKU = 'no_sku';

    public const DUPLICATE = 'duplicate';

    protected $fillable = [
        'woo_id',
        'sku',
        'normalized_sku',
        'name',
        'type',
        'status',
        'price',
        'regular_price',
        'sale_price',
        'stock_status',
        'stock_quantity',
        'images_count',
        'permalink',
        'woo_modified_at',
        'match_status',
        'product_id',
    ];

    protected $casts = [
        'woo_id' => 'integer',
        'price' => 'float',
        'regular_price' => 'float',
        'sale_price' => 'float',
        'stock_quantity' => 'integer',
        'images_count' => 'integer',
        'woo_modified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
