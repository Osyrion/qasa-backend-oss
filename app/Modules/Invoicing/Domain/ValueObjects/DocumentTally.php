<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

/**
 * How many documents are in some state, and what they are worth together.
 *
 * The pair rather than the documents: a work queue shows a count and a sum,
 * and handing over the documents to be counted outside the module would make
 * the size of the answer depend on the size of the backlog.
 */
final readonly class DocumentTally
{
    public function __construct(
        public int $count,
        public float $amount,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0.0);
    }
}
