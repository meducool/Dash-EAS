<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tyre profile (e.g. "PRIMACY 4") of a brand — reference list for the product form. */
class BrandProfile extends Model
{
    protected $fillable = [
        'brand_id',
        'name',
        'code',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
