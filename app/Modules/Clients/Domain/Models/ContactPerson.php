<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\Models;

use Database\Factories\Modules\Clients\Domain\Models\ContactPersonFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Client|null $client
 * @property-read string $full_name
 *
 * @method static ContactPersonFactory factory($count = null, $state = [])
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> query()
 *
 * @mixin Eloquent
 */
class ContactPerson extends Model
{
    /** @use HasFactory<ContactPersonFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'contact_persons';

    protected $fillable = [
        'client_id',
        'title',
        'name',
        'surname',
        'email',
        'phone',
        'role',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->title,
            $this->name,
            $this->surname,
        ])));
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
