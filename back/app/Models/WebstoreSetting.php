<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Connection to the WooCommerce shop (single row). API keys are encrypted at rest and never serialised. */
class WebstoreSetting extends Model
{
    protected $fillable = [
        'store_url',
        'consumer_key',
        'consumer_secret',
        'consumer_key_hint',
        'margin_percent',
        'create_status',
        'auto_prepare',
        'last_auto_run_at',
        'last_auto_result',
        'last_publish_at',
        'last_pull_at',
        'last_pull_summary',
    ];

    protected $casts = [
        'consumer_key' => 'encrypted',
        'consumer_secret' => 'encrypted',
        'margin_percent' => 'float',
        'last_pull_at' => 'datetime',
        'last_publish_at' => 'datetime',
        'auto_prepare' => 'boolean',
        'last_auto_run_at' => 'datetime',
        'last_auto_result' => 'array',
        'last_pull_summary' => 'array',
    ];

    protected $hidden = ['consumer_key', 'consumer_secret'];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::query()->create([]);
    }

    public function hasCredentials(): bool
    {
        return filled($this->store_url) && filled($this->consumer_key) && filled($this->consumer_secret);
    }
}
