<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Str;

/**
 * Idempotent: an existing token is returned as-is unless $regenerate is set,
 * in which case a fresh token replaces it and the old link stops resolving.
 */
readonly class CreateQuotePublicLinkAction
{
    /**
     * @throws DomainException
     */
    public function execute(Quote $quote, bool $regenerate = false): Quote
    {
        if ($quote->isDraft()) {
            throw DomainException::because(__('invoicing.public_link_draft_forbidden'));
        }

        if ($quote->public_token !== null && ! $regenerate) {
            return $quote;
        }

        $quote->forceFill(['public_token' => Str::random(64)])->save();

        return $quote;
    }
}
