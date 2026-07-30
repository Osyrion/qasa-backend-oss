<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OSS core default for the `feature:` alias.
 *
 * Several core route files gate optional features (`feature:quotes`,
 * `feature:bank_import`, …). Those gates only mean something where plans and
 * subscriptions exist, so the SaaS overlay replaces this alias with
 * RequiresFeature. Without a core default the alias would be undefined in the
 * OSS build and every gated route would 500 at dispatch — a failure the arch
 * tests cannot see, since aliases resolve at request time.
 *
 * The OSS edition is unlimited by definition, so every feature passes.
 */
class AllowAllFeatures
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        return $next($request);
    }
}
