<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Auth\Application\Contracts\AccountRepresentation;
use App\Modules\Clients\Application\Contracts\CompanyRegistryLookup;
use App\Modules\Clients\Application\DTOs\CompanyRegistryData;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\Actions\CompleteResidencyAction;
use App\Modules\Taxation\Application\DTOs\CompleteResidencyData;
use App\Modules\Taxation\Application\DTOs\RegistryLookupData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

class ResidencyController extends Controller
{
    public function __construct(
        private readonly CompleteResidencyAction $completeResidencyAction,
        private readonly CompanyRegistryLookup $fetchCompanyData,
        private readonly AccountRepresentation $accountRepresentation,
    ) {}

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/auth/complete-residency',
        summary: 'Step 2 of registration — set tax residency, IČO and billing details (once, immutable after)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['country', 'ico', 'company_name', 'address', 'city', 'postal_code'],
                properties: [
                    new OA\Property(property: 'country', type: 'string', enum: ['SK', 'CZ'], example: 'SK'),
                    new OA\Property(property: 'ico', type: 'string', example: '12345678', maxLength: 20),
                    new OA\Property(property: 'dic', type: 'string', example: '1234567890', nullable: true, maxLength: 20),
                    new OA\Property(property: 'vat_id', type: 'string', example: 'SK1234567890', nullable: true, maxLength: 20),
                    new OA\Property(property: 'company_name', type: 'string', example: 'Ján Novák — JN Services', maxLength: 255),
                    new OA\Property(property: 'address', type: 'string', example: 'Hlavná 1', maxLength: 255),
                    new OA\Property(property: 'city', type: 'string', example: 'Bratislava', maxLength: 100),
                    new OA\Property(property: 'postal_code', type: 'string', example: '811 01', maxLength: 10),
                ]
            )
        ),
        tags: ['Taxation'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Residency completed',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')],
                    type: 'object',
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Email not verified'),
            new OA\Response(response: 422, description: 'Validation error, or residency already set'),
        ]
    )]
    public function complete(Request $request): JsonResponse
    {
        /** @var Account&Model&ProvidesSupplierProfile $user */
        $user = $request->user();

        $data = CompleteResidencyData::validateAndCreate($request->all());

        try {
            $this->completeResidencyAction->execute($user, $data);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->accountRepresentation->forAccount($user)]);
    }

    #[OA\Get(
        path: '/api/v1/auth/registry-lookup',
        summary: 'Fetch company data from a public register (ARES/RPO) by IČO, for step-2 prefill',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['SK', 'CZ'])),
            new OA\Parameter(name: 'ico', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Company data prefill',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'company_name', type: 'string', nullable: true),
                    new OA\Property(property: 'ico', type: 'string', nullable: true),
                    new OA\Property(property: 'dic', type: 'string', nullable: true),
                    new OA\Property(property: 'vat_id', type: 'string', nullable: true),
                    new OA\Property(property: 'address', type: 'string', nullable: true),
                    new OA\Property(property: 'city', type: 'string', nullable: true),
                    new OA\Property(property: 'postal_code', type: 'string', nullable: true),
                    new OA\Property(property: 'country', type: 'string', enum: ['SK', 'CZ']),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Email not verified'),
            new OA\Response(response: 422, description: 'Register unreachable or IČO not found — client should fall back to manual entry'),
        ]
    )]
    public function lookup(Request $request): CompanyRegistryData|JsonResponse
    {
        $data = RegistryLookupData::validateAndCreate($request->all());

        try {
            return $this->fetchCompanyData->execute($data->country, $data->ico);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
