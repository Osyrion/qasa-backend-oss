<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Controllers;

use App\Modules\Clients\Application\Actions\FetchCompanyDataAction;
use App\Modules\Clients\Application\Actions\VerifyVatAction;
use App\Modules\Clients\Application\DTOs\CompanyRegistryData;
use App\Modules\Clients\Application\DTOs\LookupCompanyData;
use App\Modules\Clients\Application\DTOs\VatValidationData;
use App\Modules\Clients\Application\DTOs\VerifyVatData;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Clients',
    description: 'Client management endpoints'
)]
class CompanyLookupController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly FetchCompanyDataAction $fetchCompanyData,
        private readonly VerifyVatAction $verifyVat,
    ) {}

    #[OA\Get(
        path: '/api/v1/clients/lookup',
        summary: 'Fetch company data from a public register (ARES/RPO) by IČO',
        security: [['sanctum' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['CZ', 'SK'])),
            new OA\Parameter(name: 'ico', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Company data prefill'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Company not found or unsupported country'),
        ]
    )]
    public function lookup(Request $request): CompanyRegistryData|JsonResponse
    {
        $this->authorize('create', Client::class);

        $data = LookupCompanyData::validateAndCreate($request->all());

        try {
            return $this->fetchCompanyData->execute($data->country, $data->ico);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/clients/verify-vat',
        summary: 'Verify a VAT identification number (IČ DPH) via VIES',
        security: [['sanctum' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 2)),
            new OA\Parameter(name: 'vat_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VAT validation result'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'VIES service unavailable'),
        ]
    )]
    public function verifyVat(Request $request): VatValidationData|JsonResponse
    {
        $this->authorize('create', Client::class);

        $data = VerifyVatData::validateAndCreate($request->all());

        try {
            return $this->verifyVat->execute($data->country, $data->vat_id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
