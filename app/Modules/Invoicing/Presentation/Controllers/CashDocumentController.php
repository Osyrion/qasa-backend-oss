<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\CreateCashDocumentAction;
use App\Modules\Invoicing\Application\Actions\ReverseCashDocumentAction;
use App\Modules\Invoicing\Application\DTOs\CashDocumentData;
use App\Modules\Invoicing\Application\Services\CashBookService;
use App\Modules\Invoicing\Application\Services\CashDocumentPdfService;
use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Invoicing\Presentation\Resources\CashDocumentResource;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\ContentDisposition;
use App\Modules\Shared\Support\Pagination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * Cash receipts and payments, plus the cash book they add up to.
 *
 * There is no update and no delete: a cash document is an accounting
 * record, and the correction path is `reverse`. That is not an omission —
 * it is the reason the history can be trusted.
 */
#[OA\Tag(name: 'Cash', description: 'Cash receipts and payments (PPD/VPD) and the cash book')]
class CashDocumentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreateCashDocumentAction $createAction,
        private readonly ReverseCashDocumentAction $reverseAction,
        private readonly CashBookService $cashBook,
        private readonly CashDocumentPdfService $pdf,
    ) {}

    #[OA\Get(
        path: '/api/v1/cash-documents',
        summary: 'List cash documents',
        security: [['sanctum' => []]],
        tags: ['Cash'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated cash documents'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CashDocument::class);

        $query = CashDocument::query()->orderByDesc('issued_at')->orderByDesc('number');

        $type = CashDocumentType::tryFrom($request->string('type')->toString());

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        if ($request->filled('from')) {
            $query->whereDate('issued_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('issued_at', '<=', $request->string('to')->toString());
        }

        return CashDocumentResource::collection($query->paginate(Pagination::perPage($request)));
    }

    #[OA\Post(
        path: '/api/v1/cash-documents',
        summary: 'Issue a cash receipt or payment',
        security: [['sanctum' => []]],
        tags: ['Cash'],
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation failed, or the linked payment/expense is not this account\'s'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CashDocument::class);

        /** @var User $user */
        $user = $request->user();

        $data = CashDocumentData::validateAndCreate($request->all());

        try {
            $document = $this->createAction->execute($user, $data);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new CashDocumentResource($document)], 201);
    }

    #[OA\Get(
        path: '/api/v1/cash-documents/{id}',
        summary: 'Show one cash document',
        security: [['sanctum' => []]],
        tags: ['Cash'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Cash document'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(CashDocument $cashDocument): CashDocumentResource
    {
        $this->authorize('view', $cashDocument);

        return new CashDocumentResource($cashDocument->load(['reversal', 'reverses']));
    }

    #[OA\Get(
        path: '/api/v1/cash-documents/{id}/pdf',
        summary: 'The printable slip both sides sign',
        security: [['sanctum' => []]],
        tags: ['Cash'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function pdf(CashDocument $cashDocument): Response
    {
        $this->authorize('view', $cashDocument);

        return response($this->pdf->generate($cashDocument), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::attachment($this->pdf->filename($cashDocument)),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/cash-documents/{id}/reverse',
        summary: 'Reverse a cash document by issuing its mirror image',
        description: 'The only correction path — cash documents are never updated or deleted. Both rows stay in the book and cancel out.',
        security: [['sanctum' => []]],
        tags: ['Cash'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'The reversing document'),
            new OA\Response(response: 422, description: 'Already reversed, or itself a reversal'),
        ]
    )]
    public function reverse(CashDocument $cashDocument): JsonResponse
    {
        $this->authorize('create', CashDocument::class);

        try {
            $reversal = $this->reverseAction->execute($cashDocument);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new CashDocumentResource($reversal)], 201);
    }

    #[OA\Get(
        path: '/api/v1/cash-book',
        summary: 'Cash book — every cash document in date order with a running balance per currency',
        security: [['sanctum' => []]],
        tags: ['Cash'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Opening balances, entries and closing balances'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function book(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashDocument::class);

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->cashBook->build(
                $user->accountOwnerId(),
                $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : null,
                $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : null,
            ),
        ]);
    }
}
