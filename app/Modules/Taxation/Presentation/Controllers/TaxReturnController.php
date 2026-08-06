<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Support\ContentDisposition;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use App\Modules\Taxation\Application\DTOs\SystemIncomeData;
use App\Modules\Taxation\Application\DTOs\TaxReturnInputData;
use App\Modules\Taxation\Application\Services\TaxIncomeAggregator;
use App\Modules\Taxation\Application\Services\TaxReturnDraftStore;
use App\Modules\Taxation\Application\Services\TaxReturnPdfService;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class TaxReturnController extends Controller
{
    public function __construct(
        private readonly TaxIncomeAggregator $aggregator,
        private readonly TaxSystemResolverInterface $taxSystemResolver,
        private readonly TaxReturnDraftStore $draftStore,
        private readonly TaxReturnPdfService $pdfService,
    ) {}

    #[OA\Get(
        path: '/api/v1/tax-return/schema',
        summary: 'Which wizard fields/allowances apply for a year and the account tax residency',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Wizard field/allowance availability for the year',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'year', type: 'integer'),
                        new OA\Property(property: 'residency', type: 'string', enum: ['SK', 'CZ']),
                        new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CZK']),
                        new OA\Property(property: 'supports_flat_rate_category_selection', type: 'boolean'),
                        new OA\Property(property: 'flat_rate_categories', type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 409, description: 'Tax residency not completed'),
            new OA\Response(response: 422, description: 'Unsupported year'),
        ]
    )]
    public function schema(Request $request): JsonResponse
    {
        $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $year = (int) $request->integer('year');

        /** @var User $user */
        $user = $request->user();

        $taxSystem = $this->taxSystemResolver->forUser($user);
        $calculator = $taxSystem->incomeTaxReturnCalculator();

        if (! $calculator->supportsYear($year)) {
            return response()->json(['message' => __('taxation.unsupported_tax_year', ['year' => $year])], 422);
        }

        return response()->json([
            'data' => [
                'year' => $year,
                'residency' => $taxSystem->residency()->value,
                'currency' => $taxSystem->residency()->returnCurrency()->value,
                'supports_flat_rate_category_selection' => $taxSystem->residency()->value === 'CZ',
                'flat_rate_categories' => $taxSystem->residency()->value === 'CZ' ? [80, 60, 40, 30] : null,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/tax-return/system-data',
        summary: 'What the system already knows for a tax year — income, expenses, contributions (read-only wizard prefill)',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'System-derived income/expense data for the year',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'year', type: 'integer'),
                        new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CZK']),
                        new OA\Property(property: 'business_income', type: 'number', format: 'float'),
                        new OA\Property(property: 'supplier_invoice_expenses', type: 'number', format: 'float'),
                        new OA\Property(property: 'other_expenses', type: 'number', format: 'float'),
                        new OA\Property(property: 'social_contributions_paid', type: 'number', format: 'float'),
                        new OA\Property(property: 'health_contributions_paid', type: 'number', format: 'float'),
                        new OA\Property(property: 'income_tax_advances_paid', type: 'number', format: 'float'),
                        new OA\Property(property: 'total_actual_expenses', type: 'number', format: 'float'),
                        new OA\Property(
                            property: 'unconverted_amounts',
                            type: 'array',
                            items: new OA\Items(properties: [
                                new OA\Property(property: 'currency', type: 'string'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                                new OA\Property(property: 'date', type: 'string', format: 'date'),
                            ], type: 'object')
                        ),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Feature not available on the current plan'),
            new OA\Response(response: 409, description: 'Tax residency not completed'),
            new OA\Response(response: 422, description: 'Invalid or missing year'),
        ]
    )]
    public function systemData(Request $request): JsonResponse
    {
        $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        /** @var User $user */
        $user = $request->user();

        $residency = $this->taxSystemResolver->forUser($user)->residency();
        $data = $this->aggregator->aggregate($user, (int) $request->integer('year'), $residency);

        return response()->json(['data' => $this->systemIncomeToArray($data)]);
    }

    #[OA\Put(
        path: '/api/v1/tax-return/draft',
        summary: 'Save wizard progress — encrypted, TTL 7 days, never touches the database',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['year', 'use_actual_expenses', 'is_main_activity', 'months_active', 'spouse_eligible_for_credit'],
                properties: [
                    new OA\Property(property: 'year', type: 'integer', example: 2026),
                    new OA\Property(property: 'use_actual_expenses', type: 'boolean'),
                    new OA\Property(property: 'is_main_activity', type: 'boolean'),
                    new OA\Property(property: 'months_active', type: 'integer', minimum: 0, maximum: 12),
                    new OA\Property(property: 'spouse_eligible_for_credit', type: 'boolean'),
                    new OA\Property(property: 'flat_rate_category_percent', type: 'integer', enum: [80, 60, 40, 30], nullable: true, description: 'CZ only'),
                    new OA\Property(property: 'children_ages', type: 'array', items: new OA\Items(type: 'integer')),
                    new OA\Property(property: 'employment_income', type: 'number', format: 'float'),
                    new OA\Property(property: 'other_income', type: 'number', format: 'float'),
                    new OA\Property(property: 'foreign_income', type: 'number', format: 'float'),
                ]
            )
        ),
        tags: ['Taxation'],
        responses: [new OA\Response(response: 204, description: 'Saved')]
    )]
    public function saveDraft(Request $request): JsonResponse
    {
        $data = TaxReturnInputData::validateAndCreate($request->all());

        /** @var User $user */
        $user = $request->user();

        // The validated shape, not the raw request: caching $request->all()
        // meant any extra key a caller attached was encrypted and kept for
        // seven days, unbounded and never read back by anything.
        $this->draftStore->put($user->id, $data->year, $data->toArray());

        return response()->json(null, 204);
    }

    #[OA\Get(
        path: '/api/v1/tax-return/draft',
        summary: 'Read back saved wizard progress for a year',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Draft found',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'year', type: 'integer'),
                        new OA\Property(property: 'use_actual_expenses', type: 'boolean'),
                        new OA\Property(property: 'is_main_activity', type: 'boolean'),
                        new OA\Property(property: 'months_active', type: 'integer'),
                        new OA\Property(property: 'spouse_eligible_for_credit', type: 'boolean'),
                        new OA\Property(property: 'flat_rate_category_percent', type: 'integer', nullable: true),
                        new OA\Property(property: 'children_ages', type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(property: 'employment_income', type: 'number', format: 'float'),
                        new OA\Property(property: 'other_income', type: 'number', format: 'float'),
                        new OA\Property(property: 'foreign_income', type: 'number', format: 'float'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 404, description: 'No draft saved for this year'),
        ]
    )]
    public function getDraft(Request $request): JsonResponse
    {
        $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        /** @var User $user */
        $user = $request->user();

        $draft = $this->draftStore->get($user->id, (int) $request->integer('year'));

        if ($draft === null) {
            return response()->json(['message' => __('taxation.draft_not_found')], 404);
        }

        return response()->json(['data' => $draft]);
    }

    #[OA\Delete(
        path: '/api/v1/tax-return/draft',
        summary: 'Discard saved wizard progress for a year',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
        ],
        responses: [new OA\Response(response: 204, description: 'Discarded')]
    )]
    public function deleteDraft(Request $request): JsonResponse
    {
        $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        /** @var User $user */
        $user = $request->user();

        $this->draftStore->forget($user->id, (int) $request->integer('year'));

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/tax-return/preview',
        summary: 'Stateless tax return worksheet — nothing is saved, disclaimers included',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['year', 'use_actual_expenses', 'is_main_activity', 'months_active', 'spouse_eligible_for_credit'],
                properties: [
                    new OA\Property(property: 'year', type: 'integer', example: 2026),
                    new OA\Property(property: 'use_actual_expenses', type: 'boolean'),
                    new OA\Property(property: 'is_main_activity', type: 'boolean'),
                    new OA\Property(property: 'months_active', type: 'integer', minimum: 0, maximum: 12),
                    new OA\Property(property: 'spouse_eligible_for_credit', type: 'boolean'),
                    new OA\Property(property: 'flat_rate_category_percent', type: 'integer', enum: [80, 60, 40, 30], nullable: true, description: 'CZ only'),
                    new OA\Property(property: 'children_ages', type: 'array', items: new OA\Items(type: 'integer')),
                    new OA\Property(property: 'employment_income', type: 'number', format: 'float'),
                    new OA\Property(property: 'other_income', type: 'number', format: 'float'),
                    new OA\Property(property: 'foreign_income', type: 'number', format: 'float'),
                ]
            )
        ),
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf']), description: 'Set to "pdf" for a downloadable worksheet instead of JSON'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Computed worksheet breakdown (JSON) or PDF worksheet',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'year', type: 'integer'),
                        new OA\Property(property: 'currency', type: 'string'),
                        new OA\Property(property: 'partial_tax_base_business', type: 'number', format: 'float'),
                        new OA\Property(property: 'total_tax_base', type: 'number', format: 'float'),
                        new OA\Property(property: 'expenses_used', type: 'number', format: 'float'),
                        new OA\Property(property: 'used_flat_rate_expenses', type: 'boolean'),
                        new OA\Property(property: 'tax_before_credits', type: 'number', format: 'float'),
                        new OA\Property(property: 'tax_credits', type: 'number', format: 'float'),
                        new OA\Property(property: 'child_tax_bonus', type: 'number', format: 'float'),
                        new OA\Property(property: 'final_tax', type: 'number', format: 'float'),
                        new OA\Property(property: 'advances_paid', type: 'number', format: 'float'),
                        new OA\Property(property: 'tax_balance', type: 'number', format: 'float'),
                        new OA\Property(property: 'contributions', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'number', format: 'float')),
                        new OA\Property(property: 'notes', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'disclaimer', type: 'string'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 422, description: 'Invalid input or unsupported year'),
        ]
    )]
    public function preview(Request $request): JsonResponse|Response
    {
        $data = TaxReturnInputData::validateAndCreate($request->all());

        /** @var User $user */
        $user = $request->user();

        Log::info('taxation.tax_return_preview', ['user_id' => $user->id, 'year' => $data->year]);

        $taxSystem = $this->taxSystemResolver->forUser($user);
        $systemIncome = $this->aggregator->aggregate($user, $data->year, $taxSystem->residency());

        $input = new TaxReturnInput(
            year: $data->year,
            systemIncome: $systemIncome,
            useActualExpenses: $data->use_actual_expenses,
            flatRateCategoryPercent: $data->flat_rate_category_percent,
            isMainActivity: $data->is_main_activity,
            monthsActive: $data->months_active,
            childrenAges: $data->children_ages,
            spouseEligibleForCredit: $data->spouse_eligible_for_credit,
            employmentIncome: $data->employment_income,
            otherIncome: $data->other_income,
            foreignIncome: $data->foreign_income,
        );

        $result = $taxSystem->incomeTaxReturnCalculator()->calculate($input);

        if ($request->query('format') === 'pdf') {
            return response($this->pdfService->generate($result), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => ContentDisposition::attachment($this->pdfService->filename($result)),
            ]);
        }

        return response()->json([
            'data' => [
                'year' => $result->year,
                'currency' => $result->currency,
                'partial_tax_base_business' => $result->partialTaxBaseBusiness,
                'total_tax_base' => $result->totalTaxBase,
                'expenses_used' => $result->expensesUsed,
                'used_flat_rate_expenses' => $result->usedFlatRateExpenses,
                'tax_before_credits' => $result->taxBeforeCredits,
                'tax_credits' => $result->taxCredits,
                'child_tax_bonus' => $result->childTaxBonus,
                'final_tax' => $result->finalTax,
                'advances_paid' => $result->advancesPaid,
                'tax_balance' => $result->taxBalance,
                'contributions' => $result->contributions,
                'notes' => $result->notes,
                'disclaimer' => __('taxation.worksheet_disclaimer'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function systemIncomeToArray(SystemIncomeData $data): array
    {
        return [
            'year' => $data->year,
            'currency' => $data->currency->value,
            'business_income' => $data->businessIncome,
            'supplier_invoice_expenses' => $data->supplierInvoiceExpenses,
            'other_expenses' => $data->otherExpenses,
            'social_contributions_paid' => $data->socialContributionsPaid,
            'health_contributions_paid' => $data->healthContributionsPaid,
            'income_tax_advances_paid' => $data->incomeTaxAdvancesPaid,
            'total_actual_expenses' => $data->totalActualExpenses(),
            'unconverted_amounts' => $data->unconvertedAmounts,
        ];
    }
}
