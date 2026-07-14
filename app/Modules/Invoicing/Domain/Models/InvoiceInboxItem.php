<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
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
 * @property string|null $matched_client_id
 * @property Carbon $scanned_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Client|null $matchedClient
 * @property-read SupplierInvoice|null $supplierInvoice
 * @property-read string|null $url
 * @property-read User|null $user
 *
 * @method static InvoiceInboxItemFactory factory($count = null, $state = [])
 * @method static Builder<static>|InvoiceInboxItem forUser($userId = null)
 * @method static Builder<static>|InvoiceInboxItem newModelQuery()
 * @method static Builder<static>|InvoiceInboxItem newQuery()
 * @method static Builder<static>|InvoiceInboxItem onlyTrashed()
 * @method static Builder<static>|InvoiceInboxItem query()
 * @method static Builder<static>|InvoiceInboxItem withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|InvoiceInboxItem withoutTrashed()
 * @method static Builder<static>|InvoiceInboxItem whereCreatedAt($value)
 * @method static Builder<static>|InvoiceInboxItem whereDeletedAt($value)
 * @method static Builder<static>|InvoiceInboxItem whereDisk($value)
 * @method static Builder<static>|InvoiceInboxItem whereError($value)
 * @method static Builder<static>|InvoiceInboxItem whereFileHash($value)
 * @method static Builder<static>|InvoiceInboxItem whereId($value)
 * @method static Builder<static>|InvoiceInboxItem whereMatchedClientId($value)
 * @method static Builder<static>|InvoiceInboxItem whereMimeType($value)
 * @method static Builder<static>|InvoiceInboxItem whereOcrEngine($value)
 * @method static Builder<static>|InvoiceInboxItem whereOcrText($value)
 * @method static Builder<static>|InvoiceInboxItem whereOriginalFilename($value)
 * @method static Builder<static>|InvoiceInboxItem wherePath($value)
 * @method static Builder<static>|InvoiceInboxItem whereScannedAt($value)
 * @method static Builder<static>|InvoiceInboxItem whereSizeBytes($value)
 * @method static Builder<static>|InvoiceInboxItem whereStatus($value)
 * @method static Builder<static>|InvoiceInboxItem whereSuggestions($value)
 * @method static Builder<static>|InvoiceInboxItem whereSupplierInvoiceId($value)
 * @method static Builder<static>|InvoiceInboxItem whereUpdatedAt($value)
 * @method static Builder<static>|InvoiceInboxItem whereUserId($value)
 *
 * @mixin Eloquent
 */
class InvoiceInboxItem extends Model
{
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
}
