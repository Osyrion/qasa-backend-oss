<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Requests;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared client_id ownership check for StoreInvoiceRequest/UpdateInvoiceRequest
 * — the two currently validate identically, but are kept as separate classes
 * (rather than one shared instance) since store/update are free to diverge
 * without another extraction pass.
 */
abstract class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // InvoiceController::__construct() already wires
        // authorizeResource(Invoice::class, 'invoice') for store/update.
        return true;
    }

    /**
     * @return array<int, mixed>
     */
    protected function clientIdRules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'required', 'uuid',
            Rule::exists('clients', 'id')
                ->where('user_id', $user->accountOwnerId())
                ->whereNull('deleted_at'),
        ];
    }
}
