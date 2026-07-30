<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale, in priority order:
 * authenticated user's stored preference, Accept-Language header, app default.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        /** @var list<string> $available */
        $available = config('qasa.locales.available', [config('app.locale')]);

        $userLocale = $request->user('sanctum')?->locale;

        if (is_string($userLocale) && in_array($userLocale, $available, true)) {
            return $userLocale;
        }

        return $request->getPreferredLanguage($available) ?? (string) config('app.fallback_locale');
    }
}
