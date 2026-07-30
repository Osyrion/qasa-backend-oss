<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\AuthorizesTool;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Presentation\Resources\ClientResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SearchClientsTool extends Tool
{
    use AuthorizesTool;

    protected string $description = 'Search the authenticated account\'s clients and vendors by name, company name, email, or IČO. Read-only — never invents a client that is not returned here.';

    public function __construct(
        private readonly ClientRepositoryInterface $clients,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Free-text search across name, surname, company name, email, and IČO'),
            'role' => $schema->string()
                ->enum(['customer', 'vendor', 'all'])
                ->description('Filter by relationship to the account')
                ->default('customer'),
            'status' => $schema->string()
                ->enum(['active', 'archived', 'all'])
                ->description('Filter by archive status')
                ->default('active'),
            'client_type' => $schema->string()
                ->enum(['individual', 'self_employed', 'company'])
                ->description('Filter by client type'),
            'per_page' => $schema->integer()
                ->min(1)->max(50)
                ->description('Results per page')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        Gate::forUser($user)->authorize('viewAny', Client::class);

        $clients = $this->clients->paginate(
            perPage: (int) $request->get('per_page', 20),
            filters: $request->all(['search', 'role', 'status', 'client_type']),
        );

        return Response::json([
            'data' => ClientResource::collection($clients)->resolve(),
            'pagination' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
            ],
        ]);
    }
}
