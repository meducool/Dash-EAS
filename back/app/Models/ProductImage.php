<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Photo of a catalogue article, stored on the public disk (products/{product_id}/). */
class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'original_name',
        'size',
        'position',
        'created_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'position' => 'integer',
    ];

    protected $appends = ['url'];

    protected $hidden = ['path'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => asset('storage/'.ltrim($this->path, '/')));
    }
}
