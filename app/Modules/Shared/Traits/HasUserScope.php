<?php

declare(strict_types=1);

namespace App\Modules\Shared\Traits;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

trait HasUserScope
{
    /**
     * Scope a query to only include records of the given account —
     * for team members that is the owner's account.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, ?string $userId = null): Builder
    {
        $user = auth()->user();
        $userId = $userId ?? ($user instanceof User ? $user->accountOwnerId() : null);

        return $query->where($this->getTable().'.user_id', $userId);
    }

    /**
     * Boot the trait to automatically apply the account scope. The instanceof
     * guard keeps an authenticated AdminUser (admin guard) from being treated
     * as a tenant.
     */
    protected static function bootHasUserScope(): void
    {
        static::addGlobalScope('user', function (Builder $query) {
            $user = auth()->user();

            if ($user instanceof User) {
                $query->where($query->getModel()->getTable().'.user_id', $user->accountOwnerId());
            }
        });

        // Ownership is assigned once, at creation, from server-side context.
        // `user_id` is mass-assignable so repositories can set it on create,
        // which leaves a re-assignment footgun: a future `update($request…)`
        // could hand a record to another account. Freeze the column on every
        // existing record so that can never happen, whatever the caller does.
        static::updating(function (Model $model): void {
            if ($model->isDirty('user_id')) {
                throw new RuntimeException('The owning user_id of a scoped record cannot be changed.');
            }
        });
    }
}
