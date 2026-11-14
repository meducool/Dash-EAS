<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One generation of proposed changes for the online shop. Only the latest is open. */
class WebstoreBatch extends Model
{
    public const OPEN = 'open';

    public const SUPERSEDED = 'superseded';

    protected $fillable = [
        'status',
        'margin_percent',
        'catalogue_read_at',
        'counts',
        'created_by',
        'created_by_name',
    ];

    protected $casts = [
        'margin_percent' => 'float',
        'catalogue_read_at' => 'datetime',
        'counts' => 'array',
    ];

    public function changes(): HasMany
    {
        return $this->hasMany(WebstoreChange::class, 'batch_id');
    }
}
