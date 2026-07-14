<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use Database\Factories\Modules\Orders\Domain\Models\OrderAttachmentFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $order_id
 * @property string $user_id
 * @property string $disk
 * @property string|null $path Relative path for local/r2
 * @property string|null $external_id Document ID from external provider
 * @property string|null $external_url Direct webUrl from provider
 * @property string $filename Original filename for display
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $label Optional label, e.g. Zmluva o dielo
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $display_name
 * @property-read string|null $url
 * @property-read Order|null $order
 * @property-read User|null $user
 *
 * @method static OrderAttachmentFactory factory($count = null, $state = [])
 * @method static Builder<static>|OrderAttachment newModelQuery()
 * @method static Builder<static>|OrderAttachment newQuery()
 * @method static Builder<static>|OrderAttachment query()
 * @method static Builder<static>|OrderAttachment whereCreatedAt($value)
 * @method static Builder<static>|OrderAttachment whereDisk($value)
 * @method static Builder<static>|OrderAttachment whereExternalId($value)
 * @method static Builder<static>|OrderAttachment whereExternalUrl($value)
 * @method static Builder<static>|OrderAttachment whereFilename($value)
 * @method static Builder<static>|OrderAttachment whereId($value)
 * @method static Builder<static>|OrderAttachment whereLabel($value)
 * @method static Builder<static>|OrderAttachment whereMimeType($value)
 * @method static Builder<static>|OrderAttachment whereOrderId($value)
 * @method static Builder<static>|OrderAttachment wherePath($value)
 * @method static Builder<static>|OrderAttachment whereSizeBytes($value)
 * @method static Builder<static>|OrderAttachment whereSortOrder($value)
 * @method static Builder<static>|OrderAttachment whereUpdatedAt($value)
 * @method static Builder<static>|OrderAttachment whereUserId($value)
 *
 * @mixin Eloquent
 */
class OrderAttachment extends Model
{
    /** @use HasFactory<OrderAttachmentFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'order_id',
        'user_id',
        'disk',
        'path',
        'external_id',
        'external_url',
        'filename',
        'mime_type',
        'size_bytes',
        'label',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        return $this->label ?? $this->filename;
    }

    public function getUrlAttribute(): ?string
    {
        /** @var string $path */
        $path = $this->path;

        return match ($this->disk) {
            'local', 'r2' => Storage::disk($this->disk)->url($path),
            'sharepoint', 'onedrive' => $this->external_url,
            default => null,
        };
    }

    public function isExternal(): bool
    {
        return in_array($this->disk, ['sharepoint', 'onedrive']);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
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

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
