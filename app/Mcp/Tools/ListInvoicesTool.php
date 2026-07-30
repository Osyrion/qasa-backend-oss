<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AuthorizesTool;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Resources\InvoiceResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListInvoicesTool extends Tool
{
    use AuthorizesTool;

    protected string $description = 'List the authenticated account\'s invoices, quotes, and credit notes with optional status/client/currency/date filters. Read-only — never invents an invoice number or amount that is not returned here.';

    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['draft', 'issued', 'sent', 'reminded', 'paid', 'cancelled', 'credited'])
                ->description('Filter by invoice status'),
            'client_id' => $schema->string()
                ->description('Filter by client UUID'),
            'currency' => $schema->string()
                ->enum(['CZK', 'EUR', 'USD'])
                ->description('Filter by currency'),
            'date_from' => $schema->string()
                ->description('Filter by issue date, from (YYYY-MM-DD)'),
            'date_to' => $schema->string()
                ->description('Filter by issue date, to (YYYY-MM-DD)'),
            'overdue' => $schema->boolean()
                ->description('Only invoices past their due date and still unpaid'),
            'per_page' => $schema->integer()
                ->min(1)->max(50)
                ->description('Results per page')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        Gate::forUser($user)->authorize('viewAny', Invoice::class);

        $invoices = $this->invoices->paginate(
            perPage: (int) $request->get('per_page', 20),
            filters: $request->all(['status', 'client_id', 'currency', 'date_from', 'date_to', 'overdue']),
        );

        return Response::json([
            'data' => InvoiceResource::collection($invoices)->resolve(),
            'pagination' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }
}
