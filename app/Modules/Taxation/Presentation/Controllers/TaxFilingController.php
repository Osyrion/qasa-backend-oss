<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\ContentDisposition;
use App\Modules\Shared\Support\Pagination;
use App\Modules\Taxation\Application\DTOs\GenerateTaxFilingData;
use App\Modules\Taxation\Application\Services\TaxFilingRecapPdfService;
use App\Modules\Taxation\Application\Services\TaxFilingService;
use App\Modules\Taxation\Application\Services\TaxSystemResolver;
use App\Modules\Taxation\Domain\Enums\TaxFilingStatus;
use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\Models\TaxFiling;
use App\Modules\Taxation\Presentation\Resources\TaxFilingResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * Phase 4, Part B (docs/plans/SK_VAT_FILING_EDANE_PLAN.md) — the immutable
 * filing archive. No destroy() — a filing, once generated, is never
 * deleted, only superseded by generating a fresh one for the same period.
 */
#[OA\Tag(name: 'Taxation', description: 'Tax residency, contribution payments, income-tax-return wizard, and the filing archive')]
class TaxFilingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TaxFilingService $taxFilingService,
        private readonly TaxFilingRecapPdfService $recapPdf,
        private readonly TaxSystemResolver $taxSystems,
    ) {}

    #[OA\Get(
        path: '/api/v1/tax-filings',
        summary: 'List the account\'s archived filings',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['control_statement', 'eu_sales_list', 'income_tax', 'vat_return'])),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['generated', 'filed', 'superseded'])),
            new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list, newest first',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaxFiling')),
                    new OA\Property(property: 'meta', type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TaxFiling::class);

        $query = TaxFiling::query()->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('year')) {
            $query->where('period_year', $request->integer('year'));
        }

        $filings = $query->paginate(Pagination::perPage($request));

        return TaxFilingResource::collection($filings);
    }

    #[OA\Get(
        path: '/api/v1/tax-filings/{tax_filing}',
        summary: 'Show one archived filing, including its full content',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'tax_filing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Filing',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/TaxFiling'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(TaxFiling $taxFiling): TaxFilingResource
    {
        $this->authorize('view', $taxFiling);

        return TaxFilingResource::make($taxFiling);
    }

    #[OA\Get(
        path: '/api/v1/tax-filings/{id}/recap.pdf',
        summary: 'Human-readable recap of the archived filing, for checking before submission',
        description: 'Every value the stored document actually carries, plus the builder\'s own stated assumptions. Read out of the archive, not recomputed — the point is to show what will be filed, including figures that have since moved.',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function recap(TaxFiling $taxFiling): Response
    {
        $this->authorize('view', $taxFiling);

        $pdf = $this->recapPdf->generate($taxFiling, $this->assumptionsFor($taxFiling));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::attachment($this->recapPdf->filename($taxFiling)),
        ]);
    }

    /**
     * The caveats the builder itself publishes, carried onto the printout.
     *
     * Assumptions that only exist in a docblock protect nobody: the person
     * who needs to read "row mapping is unverified against the official
     * instructions" is the one holding the paper, not the one reading the
     * source.
     *
     * @return list<string>
     */
    private function assumptionsFor(TaxFiling $taxFiling): array
    {
        $system = $this->taxSystems->forResidency(TaxResidency::from($taxFiling->country));

        return match ($taxFiling->type) {
            TaxFilingType::VatReturn => $system->vatReturnBuilder()->assumptions(),
            TaxFilingType::ControlStatement => $system->controlStatementBuilder()->assumptions(),
            default => [],
        };
    }

    #[OA\Post(
        path: '/api/v1/tax-filings',
        summary: 'Generate a new filing snapshot for a period',
        description: 'control_statement, eu_sales_list and vat_return are generatable for both SK and CZ — all fully derivable server-side from (account, year, quarter/month) via the existing builders. Generating one for a period that already has a non-superseded filing of the same type supersedes the earlier one; the earlier snapshot is kept, never rewritten.',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'period_year'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['control_statement', 'eu_sales_list', 'vat_return']),
                    new OA\Property(property: 'period_year', type: 'integer', example: 2026),
                    new OA\Property(property: 'period_quarter', type: 'integer', nullable: true, minimum: 1, maximum: 4),
                    new OA\Property(property: 'period_month', type: 'integer', nullable: true, minimum: 1, maximum: 12),
                ]
            )
        ),
        tags: ['Taxation'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Generated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/TaxFiling'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error, or income_tax requested (not generatable)'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TaxFiling::class);

        $data = GenerateTaxFilingData::validateAndCreate($request->all());

        /** @var User $user */
        $user = $request->user();

        try {
            $filing = $this->taxFilingService->generate($user, $data->type, $data->period_year, $data->period_quarter, $data->period_month);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return TaxFilingResource::make($filing)->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/tax-filings/{tax_filing}/mark-filed',
        summary: 'Mark a generated filing as actually filed with the tax authority',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'filed_at', type: 'string', format: 'date-time', nullable: true, description: 'Defaults to now'),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
            ])
        ),
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'tax_filing', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/TaxFiling'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Not in generated status — already filed, or superseded by a newer snapshot'),
        ]
    )]
    public function markFiled(Request $request, TaxFiling $taxFiling): JsonResponse
    {
        $this->authorize('update', $taxFiling);

        if ($taxFiling->status !== TaxFilingStatus::Generated) {
            return response()->json(['message' => __('taxation.tax_filing_not_generated')], 422);
        }

        $validated = $request->validate([
            'filed_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $taxFiling->update([
            'status' => TaxFilingStatus::Filed->value,
            'filed_at' => $validated['filed_at'] ?? now(),
            'notes' => $validated['notes'] ?? $taxFiling->notes,
        ]);

        return TaxFilingResource::make($taxFiling)->response();
    }
}
