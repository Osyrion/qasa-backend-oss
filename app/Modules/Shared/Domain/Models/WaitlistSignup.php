<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An e-mail collected from a beta-waitlist landing page, and — once the beta
 * has room — the invitation issued against it.
 *
 * Deliberately not tenant-owned: no user_id/owner_id, no HasUserScope, no RLS
 * policy — signups are collected before any account exists.
 *
 * @property string $id
 * @property string $email
 * @property string $locale
 * @property string|null $page_variant
 * @property Carbon|null $invited_at
 * @property string|null $invitation_token_hash
 * @property Carbon|null $invitation_expires_at
 * @property int $invite_count
 * @property Carbon|null $registered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WaitlistSignup extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'locale',
        'page_variant',
    ];

    /**
     * @var list<string>
     *
     * The token hash is not a secret the way a password is, but it is the
     * only thing standing between a leaked row and a registration on a
     * closed beta — no reason for it to ride along in an API response.
     */
    protected $hidden = [
        'invitation_token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'registered_at' => 'datetime',
            'invite_count' => 'integer',
        ];
    }

    /**
     * Whoever is still waiting: signed up, never invited.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->whereNull('invited_at');
    }

    /**
     * Invited and gone quiet — the list worth chasing before inviting more.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingRegistration(Builder $query): void
    {
        $query->whereNotNull('invited_at')->whereNull('registered_at');
    }

    public function hasUsableInvitation(): bool
    {
        return $this->invitation_expires_at !== null
            && $this->invitation_expires_at->isFuture()
            && $this->registered_at === null;
    }
}
