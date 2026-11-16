<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounds the `per_page` query parameter of every API list: a request such as
 * `?per_page=10000000` would otherwise load a whole table in one response.
 * The largest page the front asks for is 500.
 */
class LimitPerPage
{
    public const MAX = 1000;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has('per_page')) {
            $perPage = max(1, min(self::MAX, (int) $request->query('per_page')));
            $request->query->set('per_page', $perPage);
            $request->merge(['per_page' => $perPage]);
        }

        return $next($request);
    }
}
