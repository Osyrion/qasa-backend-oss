<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Http\Request;

final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;

    /**
     * Upper bound on page size — an unbounded per_page lets a single
     * request hydrate the whole table.
     */
    public const MAX_PER_PAGE = 100;

    public static function perPage(Request $request, int $default = self::DEFAULT_PER_PAGE): int
    {
        return min(max((int) $request->input('per_page', $default), 1), self::MAX_PER_PAGE);
    }
}
