<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Contracts;

final readonly class ExtractionResult
{
    public function __construct(
        public string $text,
        public string $engine,
    ) {}
}
