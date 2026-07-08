<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceTemplateRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\RecurringTemplateData;
use App\Modules\Invoicing\Domain\Enums\RecurringTemplateStatus;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateRecurringTemplateAction
{
    public function __construct(
        private RecurringInvoiceTemplateRepositoryInterface $repository,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(RecurringTemplateData $data, User $user): RecurringInvoiceTemplate
    {
        return DB::transaction(function () use ($data, $user): RecurringInvoiceTemplate {
            $template = $this->repository->create([
                'user_id' => $user->accountOwnerId(),
                'client_id' => $data->client_id,
                'name' => $data->name,
                'status' => RecurringTemplateStatus::Active->value,
                'period' => $data->period->value,
                'day_of_month' => $data->day_of_month,
                'last_day_of_month' => $data->last_day_of_month,
                'first_issue_date' => $data->first_issue_date,
                'end_date' => $data->end_date,
                'next_run_date' => $data->first_issue_date,
                'type' => $data->type->value,
                'currency' => $data->currency->value,
                'due_days' => $data->due_days,
                'discount_percent' => $data->discount_percent,
                'tax_date_mode' => $data->tax_date_mode->value,
                'auto_send' => $data->auto_send,
                'note_above' => $data->note_above,
                'note_below' => $data->note_below,
            ]);

            foreach ($data->items as $index => $item) {
                $template->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'vat_rate' => $item->vat_rate,
                    'sort_order' => $item->sort_order !== 0 ? $item->sort_order : $index,
                ]);
            }

            return $template->load('items');
        });
    }
}
