<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Refuses addresses on known throwaway-inbox domains.
 *
 * The cheapest abuse path against a free trial is a new inbox, and these
 * domains hand one out in a single click. Blocking them is not a complete
 * defence and is not meant to be — the list goes stale by the week, and
 * anyone determined will register a domain of their own. It removes the
 * zero-effort case; the phone verification behind the trial is what makes
 * the effort actually cost something.
 *
 * Subdomain-aware: mail.yopmail.com is yopmail.com's, and matching only the
 * exact host would let a wildcard DNS entry walk straight past this.
 */
final class DisposableEmailBlocked implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $at = mb_strrpos($value, '@');

        if ($at === false) {
            return; // Not an address at all — the email rule reports that.
        }

        $domain = mb_strtolower(mb_substr($value, $at + 1));

        /** @var list<string> $blocked */
        $blocked = config('registration.disposable_email_domains', []);

        foreach ($blocked as $blockedDomain) {
            if ($domain === $blockedDomain || Str::endsWith($domain, '.'.$blockedDomain)) {
                $fail(__('shared.disposable_email_blocked'));

                return;
            }
        }
    }
}
