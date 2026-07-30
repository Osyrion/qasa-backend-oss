<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\Quote;

readonly class RevokeQuotePublicLinkAction
{
    public function execute(Quote $quote): Quote
    {
        $quote->forceFill(['public_token' => null])->save();

        return $quote;
    }
}
