<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Invoicing\Domain\Enums\ExpenseCategory;

/**
 * One past expense reduced to "what was it called, and what was it filed as".
 *
 * The whole of what a category suggester needs, and deliberately nothing else:
 * the amount, the date, the supplier and the attachment are all things it
 * would be able to read and has no business reading. The category arrives as
 * the enum rather than the column's string, so a row filed under a value the
 * catalogue no longer has simply does not come across.
 */
final readonly class CategorisedExpense
{
    public function __construct(
        public string $description,
        public ExpenseCategory $category,
    ) {}
}
