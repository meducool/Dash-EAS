<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Permanent link POS article ↔ WooCommerce product (by the site's product id). */
class WebstoreLink extends Model
{
    public const BY_SKU = 'sku';

    public const MANUAL = 'manual';

    public const CREATED = 'created';

    protected $fillable = ['product_id', 'woo_id', 'source', 'created_by', 'created_by_name'];

    protected $casts = ['woo_id' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
