<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\CreateBankAccountAction;
use App\Modules\Invoicing\Application\Actions\UpdateBankAccountAction;
use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\BankAccountData;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Presentation\Resources\BankAccountResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'BankAccounts',
    description: 'Supplier bank accounts printed on invoices'
)]
class BankAccountController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly BankAccountRepositoryInterface $repository,
        private readonly CreateBankAccountAction $createAction,
        private readonly UpdateBankAccountAction $updateAction,
    ) {
        $this->authorizeResource(BankAccount::class, 'bank_account');
    }

    #[OA\Get(
        path: '/api/v1/bank-accounts',
        summary: 'List bank accounts',
        security: [['sanctum' => []]],
        tags: ['BankAccounts'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Bank accounts',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/BankAccount'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $accounts = $this->repository->allForUser($user->accountOwnerId());

        return BankAccountResource::collection($accounts);
    }

    #[OA\Get(
        path: '/api/v1/bank-accounts/{id}',
        summary: 'Show bank account',
        security: [['sanctum' => []]],
        tags: ['BankAccounts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Bank account', content: new OA\JsonContent(ref: '#/components/schemas/BankAccount')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(BankAccount $bankAccount): JsonResponse
    {
        return response()->json(BankAccountResource::make($bankAccount));
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/bank-accounts',
        summary: 'Create bank account',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['label', 'currency'],
                properties: [
                    new OA\Property(property: 'label', type: 'string', maxLength: 100),
                    new OA\Property(property: 'bank_name', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'account_number', type: 'string', nullable: true, maxLength: 30, example: '123456789/0100'),
                    new OA\Property(property: 'iban', type: 'string', nullable: true, maxLength: 34),
                    new OA\Property(property: 'bic', type: 'string', nullable: true, maxLength: 11),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'is_default', type: 'boolean'),
                ]
            )
        ),
        tags: ['BankAccounts'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/BankAccount')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate(BankAccountData::rules());

        /** @var User $user */
        $user = $request->user();

        $data = BankAccountData::fromRequest($request);
        $account = $this->createAction->execute($data, $user->accountOwnerId());

        return response()->json(BankAccountResource::make($account), 201);
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/bank-accounts/{id}',
        summary: 'Update bank account',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['label', 'currency'],
                properties: [
                    new OA\Property(property: 'label', type: 'string', maxLength: 100),
                    new OA\Property(property: 'bank_name', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'account_number', type: 'string', nullable: true, maxLength: 30),
                    new OA\Property(property: 'iban', type: 'string', nullable: true, maxLength: 34),
                    new OA\Property(property: 'bic', type: 'string', nullable: true, maxLength: 11),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'is_default', type: 'boolean'),
                ]
            )
        ),
        tags: ['BankAccounts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/BankAccount')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $request->validate(BankAccountData::rules());

        $data = BankAccountData::fromRequest($request);
        $updated = $this->updateAction->execute($bankAccount, $data);

        return response()->json(BankAccountResource::make($updated));
    }

    #[OA\Delete(
        path: '/api/v1/bank-accounts/{id}',
        summary: 'Delete bank account',
        security: [['sanctum' => []]],
        tags: ['BankAccounts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        $this->repository->delete($bankAccount);

        return response()->json(null, 204);
    }
}
