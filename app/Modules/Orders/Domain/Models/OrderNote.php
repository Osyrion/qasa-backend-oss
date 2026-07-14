<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use Database\Factories\Modules\Orders\Domain\Models\OrderNoteFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property string $user_id
 * @property string $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 * @property-read User|null $user
 *
 * @method static OrderNoteFactory factory($count = null, $state = [])
 * @method static Builder<static>|OrderNote newModelQuery()
 * @method static Builder<static>|OrderNote newQuery()
 * @method static Builder<static>|OrderNote query()
 * @method static Builder<static>|OrderNote whereContent($value)
 * @method static Builder<static>|OrderNote whereCreatedAt($value)
 * @method static Builder<static>|OrderNote whereId($value)
 * @method static Builder<static>|OrderNote whereOrderId($value)
 * @method static Builder<static>|OrderNote whereUpdatedAt($value)
 * @method static Builder<static>|OrderNote whereUserId($value)
 *
 * @mixin Eloquent
 */
class OrderNote extends Model
{
    /** @use HasFactory<OrderNoteFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'order_id',
        'user_id',
        'content',
    ];

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
