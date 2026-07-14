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
use Illuminate\Support\Carbon;

/**
 * @property-read Client|null $client
 * @property-read string $full_name
 *
 * @method static ContactPersonFactory factory($count = null, $state = [])
 * @method static Builder<static>|ContactPerson newModelQuery()
 * @method static Builder<static>|ContactPerson newQuery()
 * @method static Builder<static>|ContactPerson query()
 *
 * @property string $id
 * @property string $client_id
 * @property string|null $title
 * @property string $name
 * @property string $surname
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $role
 * @property bool $is_primary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|ContactPerson whereClientId($value)
 * @method static Builder<static>|ContactPerson whereCreatedAt($value)
 * @method static Builder<static>|ContactPerson whereEmail($value)
 * @method static Builder<static>|ContactPerson whereId($value)
 * @method static Builder<static>|ContactPerson whereIsPrimary($value)
 * @method static Builder<static>|ContactPerson whereName($value)
 * @method static Builder<static>|ContactPerson wherePhone($value)
 * @method static Builder<static>|ContactPerson whereRole($value)
 * @method static Builder<static>|ContactPerson whereSurname($value)
 * @method static Builder<static>|ContactPerson whereTitle($value)
 * @method static Builder<static>|ContactPerson whereUpdatedAt($value)
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
