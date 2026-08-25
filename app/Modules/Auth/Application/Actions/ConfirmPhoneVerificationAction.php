<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\ConfirmPhoneVerificationData;
use App\Modules\Auth\Domain\Events\PhoneVerified;
use App\Modules\Auth\Domain\Models\PhoneVerificationCode;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\AccountLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Spends a one-time code and marks the number proved.
 *
 * The event fired at the end is what the SaaS edition turns into a trial —
 * this class knows nothing about that, and the OSS edition has no listener
 * for it at all.
 */
class ConfirmPhoneVerificationAction
{
    /**
     * @throws Throwable
     */
    public function execute(User $user, ConfirmPhoneVerificationData $data): User
    {
        $record = $this->claimAttempt($user);

        // Outside the claim transaction: a wrong guess has to leave the
        // incremented counter behind, and anything thrown inside a
        // transaction takes its own writes down with it.
        if (! Hash::check($data->code, $record->code_hash)) {
            throw DomainException::validation(__('auth.phone_code_invalid'));
        }

        DB::transaction(function () use ($user, $record): void {
            // Re-checked here, not only at send time: nothing stops two
            // accounts holding a live code for the same number, and this is
            // the first moment the loser of that race can be told.
            $owner = AccountLookup::byPhone($record->phone);

            if ($owner !== null && $owner !== $user->id) {
                throw DomainException::validation(__('auth.phone_already_taken'));
            }

            $record->forceFill(['consumed_at' => now()])->save();

            // forceFill: phone_verified_at is deliberately not fillable, so
            // it can never be set through an ordinary profile update.
            $user->forceFill([
                'phone' => $record->phone,
                'phone_verified_at' => now(),
            ])->save();
        });

        event(new PhoneVerified($user));

        return $user;
    }

    /**
     * Takes one of the code's guesses, or explains why there is none to take.
     *
     * Its own transaction, and the only thing it writes: the increment has
     * to survive the exception that a wrong code throws afterwards,
     * otherwise the attempt cap silently stops capping anything and a
     * six-digit secret is guessable at HTTP speed.
     *
     * @throws Throwable
     */
    private function claimAttempt(User $user): PhoneVerificationCode
    {
        return DB::transaction(function () use ($user): PhoneVerificationCode {
            // lockForUpdate: two requests racing would otherwise both read
            // the same attempts value and both write back the same +1.
            $record = PhoneVerificationCode::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if (! $record instanceof PhoneVerificationCode) {
                throw DomainException::validation(__('auth.phone_code_invalid'));
            }

            if ($record->expires_at->isPast()) {
                throw DomainException::validation(__('auth.phone_code_expired'));
            }

            if ($record->attempts >= (int) config('qasa.phone_verification.max_attempts', 5)) {
                throw DomainException::validation(__('auth.phone_code_too_many_attempts'));
            }

            $record->increment('attempts');

            return $record;
        });
    }
}
