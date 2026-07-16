<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Presentation\Resources\ExpenseResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(
    name: 'Expense Attachments',
    description: 'Manage the receipt/invoice document attached to an expense'
)]
class ExpenseAttachmentController extends Controller
{
    use AuthorizesRequests;

    private const array ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp',
        'application/pdf',
    ];

    private const int|float MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024; // 20MB

    #[OA\Post(
        path: '/api/v1/expenses/{expense}/attachment',
        summary: 'Upload or replace the expense attachment',
        security: [['sanctum' => []]],
        tags: ['Expense Attachments'],
        parameters: [
            new OA\Parameter(name: 'expense', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Receipt/invoice file (max 20MB)'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Attachment saved',
                content: new OA\JsonContent(ref: '#/components/schemas/Expense')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Invalid file type or too large'),
        ]
    )]
    public function store(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return response()->json(['message' => __('invoicing.expense_attachment_file_type_not_allowed')], 422);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            return response()->json(['message' => __('invoicing.expense_attachment_too_large')], 422);
        }

        $disk = config('filesystems.default', 'local');
        $path = $file->store("expenses/{$expense->id}", $disk);

        if ($path === false) {
            return response()->json(['message' => __('invoicing.expense_attachment_save_failed')], 500);
        }

        if ($expense->hasAttachment()) {
            Storage::disk((string) $expense->attachment_disk)->delete((string) $expense->attachment_path);
        }

        $expense->update([
            'attachment_disk' => $disk,
            'attachment_path' => $path,
            'attachment_filename' => $file->getClientOriginalName(),
            'attachment_mime_type' => $mimeType,
            'attachment_size_bytes' => $file->getSize(),
        ]);

        return response()->json(ExpenseResource::make($expense->fresh()));
    }

    #[OA\Get(
        path: '/api/v1/expenses/{expense}/attachment',
        summary: 'Download the expense attachment',
        security: [['sanctum' => []]],
        tags: ['Expense Attachments'],
        parameters: [
            new OA\Parameter(name: 'expense', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'File contents',
                content: new OA\MediaType(mediaType: 'application/octet-stream')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found, or expense has no attachment'),
        ]
    )]
    public function show(Expense $expense): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $expense);

        if (! $expense->hasAttachment()) {
            return response()->json(['message' => __('invoicing.expense_attachment_missing')], 404);
        }

        return Storage::disk((string) $expense->attachment_disk)->download(
            (string) $expense->attachment_path,
            $expense->attachment_filename,
        );
    }

    #[OA\Delete(
        path: '/api/v1/expenses/{expense}/attachment',
        summary: 'Delete the expense attachment',
        security: [['sanctum' => []]],
        tags: ['Expense Attachments'],
        parameters: [
            new OA\Parameter(name: 'expense', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Attachment deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found, or expense has no attachment'),
        ]
    )]
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        if (! $expense->hasAttachment()) {
            return response()->json(['message' => __('invoicing.expense_attachment_missing')], 404);
        }

        Storage::disk((string) $expense->attachment_disk)->delete((string) $expense->attachment_path);

        $expense->update([
            'attachment_disk' => null,
            'attachment_path' => null,
            'attachment_filename' => null,
            'attachment_mime_type' => null,
            'attachment_size_bytes' => null,
        ]);

        return response()->json(null, 204);
    }
}
