<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\AddInvoiceItemAction;
use App\Modules\Invoicing\Application\Actions\CreateCorrectiveInvoiceAction;
use App\Modules\Invoicing\Application\Actions\CreateInvoiceAction;
use App\Modules\Invoicing\Application\Actions\CreateInvoicePublicLinkAction;
use App\Modules\Invoicing\Application\Actions\GenerateInvoiceFromOrderAction;
use App\Modules\Invoicing\Application\Actions\RemindInvoiceAction;
use App\Modules\Invoicing\Application\Actions\RevokeInvoicePublicLinkAction;
use App\Modules\Invoicing\Application\Actions\SendInvoiceEmailAction;
use App\Modules\Invoicing\Application\Actions\SettleProformaAction;
use App\Modules\Invoicing\Application\Actions\UpdateInvoiceAction;
use App\Modules\Invoicing\Application\Actions\UpdateInvoiceStatusAction;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Application\DTOs\InvoiceItemData;
use App\Modules\Invoicing\Application\DTOs\SendInvoiceEmailData;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Presentation\Requests\StoreInvoiceRequest;
use App\Modules\Invoicing\Presentation\Requests\UpdateInvoiceRequest;
use App\Modules\Invoicing\Presentation\Resources\InvoiceItemResource;
use App\Modules\Invoicing\Presentation\Resources\InvoiceResource;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\Pagination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CreateInvoiceAction $createAction,
        private readonly UpdateInvoiceAction $updateAction,
        private readonly AddInvoiceItemAction $addItemAction,
        private readonly GenerateInvoiceFromOrderAction $generateFromOrderAction,
        private readonly UpdateInvoiceStatusAction $updateStatusAction,
        private readonly CreateCorrectiveInvoiceAction $correctiveAction,
        private readonly SettleProformaAction $settleAction,
        private readonly SendInvoiceEmailAction $sendEmailAction,
        private readonly RemindInvoiceAction $remindAction,
        private readonly CreateInvoicePublicLinkAction $createPublicLinkAction,
        private readonly RevokeInvoicePublicLinkAction $revokePublicLinkAction,
    ) {
        $this->authorizeResource(Invoice::class, 'invoice');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = $this->invoiceRepository->paginate(
            perPage: Pagination::perPage($request),
            filters: $request->only([
                'status', 'client_id', 'currency',
                'date_from', 'date_to', 'overdue',
                'sort', 'direction',
            ]),
        );

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $invoice->load(['client', 'items']);

        return InvoiceResource::make($invoice);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = InvoiceData::fromRequest($request);
        $invoice = $this->createAction->execute($data, $user);

        return response()->json(InvoiceResource::make($invoice), 201);
    }

    /**
     * @throws Throwable
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $expectedUpdatedAt = $request->input('expected_updated_at');

        if ($expectedUpdatedAt !== null
            && $invoice->updated_at !== null
            && ! $invoice->updated_at->equalTo(CarbonImmutable::parse($expectedUpdatedAt))) {
            return response()->json([
                'message' => __('invoicing.stale_update'),
                'data' => InvoiceResource::make($invoice->load(['client', 'items'])),
            ], 409);
        }

        $data = InvoiceData::fromRequest($request);
        $updated = $this->updateAction->execute($invoice, $data);

        return response()->json(InvoiceResource::make($updated->load(['client', 'items'])));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        // The delete policy already restricts this to own draft invoices.
        $this->invoiceRepository->delete($invoice);

        return response()->json(null, 204);
    }

    public function addItem(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        /** @var User $user */
        $user = $request->user();
        $ownerId = $user->accountOwnerId();

        // Provenance FK must belong to the same account — order_items are
        // scoped through their parent orders so a caller cannot pin a foreign
        // account's record onto their own invoice item.
        $request->validate([
            ...InvoiceItemData::rules($ownerId, $user->accountOwner()->country, $invoice->issued_at->toDateString()),
            'order_item_id' => [
                'nullable', 'uuid',
                Rule::exists('order_items', 'id')->where(
                    fn (QueryBuilder $query): QueryBuilder => $query->whereIn(
                        'order_id',
                        DB::table('orders')->where('user_id', $ownerId)->select('id'),
                    ),
                ),
            ],
        ]);

        $data = InvoiceItemData::fromRequest($request);
        $item = $this->addItemAction->execute($invoice, $data);

        return response()->json(
            InvoiceItemResource::make($item),
            201,
        );
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        // The update policy restricts this to own editable (draft) invoices;
        // route-level scoped bindings guarantee the item belongs to the invoice.
        $this->authorize('update', $invoice);

        $item->delete();

        $invoice->load('items');
        $invoice->recalculateTotals()->save();

        return response()->json(null, 204);
    }

    /**
     * @throws Throwable
     */
    public function generateFromOrder(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
            'issued_at' => ['required', 'date'],
            'due_at' => ['required', 'date', 'after_or_equal:issued_at'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = $this->orderRepository->findByIdOrFail($request->input('order_id'));

        $this->authorize('view', $order);

        /** @var User $user */
        $user = $request->user();

        // The invoice is always billed to the order's client.
        $data = new InvoiceData(
            client_id: (string) $order->client_id,
            issued_at: $request->string('issued_at')->toString(),
            due_at: $request->string('due_at')->toString(),
            currency: Currency::from($request->string('currency')->toString()),
            note: $request->filled('note') ? $request->string('note')->toString() : null,
        );

        $invoice = $this->generateFromOrderAction->execute($order, $user, $data);

        $invoice->load(['client', 'items']);

        return response()->json(InvoiceResource::make($invoice), 201);
    }

    /**
     * @throws Throwable
     */
    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('updateStatus', $invoice);

        // Only statuses a user may set directly — reminded/credited are owned
        // by the remind and corrective actions and must not be set by hand.
        $request->validate([
            'status' => ['required', 'in:issued,sent,paid,cancelled'],
        ]);

        $newStatus = InvoiceStatus::from($request->input('status'));
        $updated = $this->updateStatusAction->execute($invoice, $newStatus);

        return response()->json(InvoiceResource::make($updated));
    }

    /**
     * @throws Throwable
     */
    public function email(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('email', $invoice);

        $request->validate(SendInvoiceEmailData::rules());

        $data = SendInvoiceEmailData::fromRequest($request);
        $updated = $this->sendEmailAction->execute($invoice, $data);

        return response()->json(InvoiceResource::make($updated->load(['client', 'items'])));
    }

    /**
     * @throws Throwable
     */
    public function remind(Invoice $invoice): JsonResponse
    {
        $this->authorize('remind', $invoice);

        $updated = $this->remindAction->execute($invoice);

        return response()->json(InvoiceResource::make($updated->load(['client', 'items'])));
    }

    /**
     * @throws Throwable
     */
    public function createCorrective(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        $this->authorize('create', Invoice::class);

        $request->validate([
            'type' => ['required', 'in:credit_note,storno'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $type = InvoiceType::from($request->input('type'));
        $corrective = $this->correctiveAction->execute($invoice, $type, $user);

        return response()->json(InvoiceResource::make($corrective->load(['client', 'items'])), 201);
    }

    /**
     * @throws Throwable
     */
    public function settle(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('settle', $invoice);

        /** @var User $user */
        $user = $request->user();

        $settled = $this->settleAction->execute($invoice, $user);

        return response()->json(InvoiceResource::make($settled->load(['client', 'items'])), 201);
    }

    public function createPublicLink(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('publicLink', $invoice);

        $request->validate([
            'regenerate' => ['nullable', 'boolean'],
        ]);

        $updated = $this->createPublicLinkAction->execute($invoice, $request->boolean('regenerate'));

        return response()->json([
            'token' => $updated->public_token,
            'url' => $updated->publicUrl(),
        ]);
    }

    public function revokePublicLink(Invoice $invoice): JsonResponse
    {
        $this->authorize('publicLink', $invoice);

        $this->revokePublicLinkAction->execute($invoice);

        return response()->json(null, 204);
    }
}
