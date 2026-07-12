<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Models;

use Database\Factories\Modules\Integrations\Domain\Models\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $webhook_endpoint_id
 * @property string $event Wire event name, e.g. invoice.paid
 * @property array<string, mixed> $payload
 * @property int $attempt
 * @property int|null $response_status
 * @property string|null $response_excerpt Truncated to 1 kB
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property-read WebhookEndpoint|null $endpoint
 *
 * @method static WebhookDeliveryFactory factory($count = null, $state = [])
 */
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'webhook_endpoint_id',
        'event',
        'payload',
        'attempt',
        'response_status',
        'response_excerpt',
        'delivered_at',
        'failed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt' => 'integer',
            'response_status' => 'integer',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $delivery): void {
            $delivery->created_at ??= now();
        });
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
