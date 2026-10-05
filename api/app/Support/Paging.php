<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Opt-in server-side pagination: active only when the query string carries `page`. */
class Paging
{
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 200;

    public static function requested(Request $request): bool
    {
        return $request->query->has('page');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query  filtered + scoped, ordering already applied
     * @param  callable  $map  model => array item
     */
    public static function paginate(Request $request, $query, callable $map): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $raw = $request->query('per_page');
        $perPage = ($raw === null || $raw === '' || ! is_numeric($raw))
            ? self::DEFAULT_PER_PAGE
            : min(max((int) $raw, 1), self::MAX_PER_PAGE);

        $total = (clone $query)->toBase()->getCountForPagination();
        $items = $query->forPage($page, $perPage)->get()->map($map)->values()->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
        ];
    }
}
