<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change proposed for the online shop (create a product, or update its price /
 * availability / photos). Nothing reaches the site unless it is approved.
 */
class WebstoreChange extends Model
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const PROPOSED = 'proposed';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    public const SUPERSEDED = 'superseded';

    protected $fillable = [
        'batch_id',
        'product_id',
        'woo_id',
        'action',
        'fields',
        'current',
        'proposed',
        'alerts',
        'price_override',
        'status',
        'decided_by',
        'decided_by_name',
        'decided_at',
        'published_at',
        'error',
    ];

    protected $casts = [
        'woo_id' => 'integer',
        'fields' => 'array',
        'current' => 'array',
        'proposed' => 'array',
        'alerts' => 'array',
        'price_override' => 'float',
        'decided_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(WebstoreBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Price that will be published: the one typed by the approver, else the computed one. */
    public function finalPrice(): ?float
    {
        return $this->price_override ?? (isset($this->proposed['price']) ? (float) $this->proposed['price'] : null);
    }
}
