<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Shared\Traits\HasDeferredColumns;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\InvoiceInboxItemFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $supplier_invoice_id Set once converted
 * @property string $status
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $file_hash SHA-256
 * @property string|null $ocr_text
 * @property string|null $ocr_engine pdfparser|tesseract
 * @property array<string, mixed>|null $suggestions
 * @property string|null $suggestions_source regex|ai|ai_byok — which InvoiceFieldExtractor produced "suggestions"
 * @property string|null $suggestions_provider anthropic|… — which provider produced "suggestions" when suggestions_source is ai/ai_byok
 * @property string|null $suggestions_model claude-haiku-4-5|… — which model produced "suggestions" when suggestions_source is ai/ai_byok
 * @property string|null $matched_client_id
 * @property Carbon $scanned_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Client|null $matchedClient
 * @property-read SupplierInvoice|null $supplierInvoice
 * @property-read string|null $url
 *
 * @method static InvoiceInboxItemFactory factory($count = null, $state = [])
 * @method static Builder<static> forUser($userId = null)
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> onlyTrashed()
 * @method static Builder<static> query()
 * @method static Builder<static> withTrashed(bool $withTrashed = true)
 * @method static Builder<static> withoutTrashed()
 *
 * @mixin Eloquent
 */
class InvoiceInboxItem extends Model
{
    use HasDeferredColumns;

    /** @use HasFactory<InvoiceInboxItemFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'supplier_invoice_id',
        'status',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'file_hash',
        'ocr_text',
        'ocr_engine',
        'suggestions',
        'suggestions_source',
        'suggestions_provider',
        'suggestions_model',
        'matched_client_id',
        'scanned_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'suggestions' => 'array',
            'scanned_at' => 'datetime',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function statusEnum(): InvoiceInboxStatus
    {
        return InvoiceInboxStatus::from($this->status);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function formattedSize(): string
    {
        $bytes = $this->size_bytes;

        return match (true) {
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' MB',
            $bytes >= 1_024 => round($bytes / 1_024, 1).' KB',
            default => $bytes.' B',
        };
    }

    public function getUrlAttribute(): ?string
    {
        return $this->disk === 'local' ? Storage::disk($this->disk)->url($this->path) : null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function matchedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'matched_client_id');
    }

    /**
     * ocr_text is a whole scanned document's text — tens of kilobytes a row.
     * Nothing reads it back out: the job writes it, and the search matches it
     * through a full-text index in the WHERE clause, which needs the column
     * indexed, not selected. Carrying it into a listing cost a megabyte a page
     * for nothing.
     *
     * @return list<string>
     */
    public function deferredColumns(): array
    {
        return ['ocr_text'];
    }
}
