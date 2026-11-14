<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier price list: an Excel file uploaded or read from a URL (Tarifs fournisseurs). */
class SupplierPriceFile extends Model
{
    protected $fillable = [
        'supplier_id',
        'name',
        'source_type',
        'source_url',
        'auto_refresh',
        'stored_path',
        'original_filename',
        'column_mapping',
        'margin_percent',
        'headers',
        'rows_count',
        'identified_count',
        'is_active',
        'last_imported_at',
        'last_error',
        'created_by',
    ];

    protected $casts = [
        'auto_refresh' => 'boolean',
        'is_active' => 'boolean',
        'column_mapping' => 'array',
        'headers' => 'array',
        'rows_count' => 'integer',
        'identified_count' => 'integer',
        'margin_percent' => 'float',
        'last_imported_at' => 'datetime',
    ];

    protected $hidden = ['stored_path'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierPriceItem::class, 'file_id');
    }
}
