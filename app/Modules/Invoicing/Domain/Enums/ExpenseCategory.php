<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum ExpenseCategory: string
{
    case Office = 'office';
    case Travel = 'travel';
    case Software = 'software';
    case Hardware = 'hardware';
    case Marketing = 'marketing';
    case Education = 'education';
    case Services = 'services';
    case Other = 'other';

    public function label(): string
    {
        return __('invoicing.expense_category_'.$this->value);
    }
}
