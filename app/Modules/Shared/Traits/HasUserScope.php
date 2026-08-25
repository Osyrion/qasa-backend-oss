<?php

declare(strict_types=1);

namespace App\Modules\Shared\Traits;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\FullAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

trait HasUserScope
{
    /**
     * The account this record belongs to.
     *
     * Lives here rather than being repeated in all 35 scoped models: it was
     * the same four lines every time, and every copy named the core User
     * class directly. That is the edition trap — the SaaS edition binds its
     * own User subclass through the auth config, so `belongsTo(User::class)`
     * handed back a *core* instance whose plan and role methods do not exist,
     * on a model the SaaS edition is the only one to use.
     *
     * Typed as the Account contract rather than the concrete model for the
     * same reason it is resolved through the config: this trait is what every
     * tenant-scoped model in every module inherits, and deptrac flattens a
     * trait's dependencies onto each class that uses it — one `User` named
     * here made all thirty-five of them depend on Auth.
     *
     * FullAccount is what a tenant-scoped record's account is *always*
     * guaranteed to be, stated without naming the model. Reading columns off
     * this relation one by one is what the contracts behind it replace: ask
     * for supplierProfile() and take the value.
     *
     * @return BelongsTo<Model&FullAccount, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model&FullAccount> $account */
        $account = config('auth.providers.users.model');

        return $this->belongsTo($account);
    }

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
        $userId = $userId ?? ($user instanceof Account ? $user->accountOwnerId() : null);

        return $query->where($this->getTable().'.user_id', $userId);
    }

    /**
     * Boot the trait to automatically apply the account scope. The instanceof
     * guard keeps an authenticated AdminUser (admin guard) from being treated
     * as a tenant — it is the edition's User model that implements Account.
     */
    protected static function bootHasUserScope(): void
    {
        static::addGlobalScope('user', function (Builder $query) {
            $user = auth()->user();

            if ($user instanceof Account) {
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
