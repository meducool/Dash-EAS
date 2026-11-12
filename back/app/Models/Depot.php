<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A depot (warehouse) — reference list for the `depot` of stock lots. */
class Depot extends Model
{
    protected $fillable = [
        'name',
    ];
}
