<?php

namespace App\Domain\Webstore;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * One online-shop operation at a time (catalogue read, computation, publishing,
 * morning run): two runs at once could publish a batch being replaced.
 */
class WebstoreLock
{
    private const KEY = 'webstore:operation';

    /** Long enough for a full catalogue read or publishing run. */
    private const SECONDS = 1200;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $lock = Cache::lock(self::KEY, self::SECONDS);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['operation' => ['Une opération sur la boutique en ligne est déjà en cours (lecture, calcul ou publication) : réessayez dans quelques minutes.']]);
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
