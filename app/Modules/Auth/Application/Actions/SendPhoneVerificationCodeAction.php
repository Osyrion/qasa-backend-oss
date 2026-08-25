<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\SendPhoneVerificationData;
use App\Modules\Auth\Application\Results\PhoneVerificationSendResult;
use App\Modules\Auth\Domain\Contracts\PhoneVerificationProviderInterface;
use App\Modules\Auth\Domain\Models\PhoneVerificationCode;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\AccountLookup;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Issues an SMS one-time code for the number the account claims.
 *
 * Nothing here may take registration down with it. The provider is allowed
 * to be missing, disabled or broken, and every one of those turns into an
 * ordinary "not right now" answer — see PhoneVerificationSendResult.
 */
class SendPhoneVerificationCodeAction
{
    public function __construct(
        private readonly PhoneVerificationProviderInterface $provider,
    ) {}

    public function execute(User $user, SendPhoneVerificationData $data): PhoneVerificationSendResult
    {
        if (! (bool) config('qasa.features.phone_verification')) {
            return PhoneVerificationSendResult::Disabled;
        }

        $this->guardNotAlreadyVerified($user, $data->phone);
        $this->guardChangeCooldown($user, $data->phone);
        $this->guardNotTakenElsewhere($user, $data->phone);
        $this->guardCooldown($user);

        $code = $this->generateCode();

        // Older codes die the moment a new one is asked for: two live codes
        // for one account would double the guesses an attacker gets while
        // the honest user only ever reads the newest message.
        PhoneVerificationCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $record = PhoneVerificationCode::query()->create([
            'user_id' => $user->id,
            'phone' => $data->phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('qasa.phone_verification.code_ttl_minutes', 10)),
        ]);

        // Not wrapped in a transaction on purpose — that would hold one open
        // across an outbound HTTP call. The row is removed by hand instead
        // when the send fails, so a gateway outage leaves no half-state and
        // no cooldown the user did not earn.
        try {
            $sent = $this->provider->sendCode($data->phone, $code);
        } catch (Throwable) {
            // The contract says implementations must not throw; a
            // third-party SDK that does anyway must still not become a 500.
            $sent = false;
        }

        if (! $sent) {
            $record->delete();

            return PhoneVerificationSendResult::Unavailable;
        }

        return PhoneVerificationSendResult::Sent;
    }

    /**
     * Six digits, uniformly drawn. random_int(), not rand()/mt_rand(): the
     * code is a credential for as long as it lives.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function guardNotAlreadyVerified(User $user, string $phone): void
    {
        if ($user->phone_verified_at !== null && $user->phone === $phone) {
            throw DomainException::validation(__('auth.phone_already_verified'));
        }
    }

    /**
     * Changing an already-verified number is allowed — people switch
     * operators, lose handsets, leave a company number behind — but not on a
     * loop. Every attempt is a paid SMS, and nothing else here is keyed to
     * the *account*: the per-number hourly cap resets the moment a different
     * number is entered, so without this one caller could walk through a
     * list of numbers indefinitely.
     *
     * Only applies once a number is verified. An account still trying to
     * prove its first one is not changing anything and must not be slowed
     * down, least of all while a trial window is running out.
     */
    private function guardChangeCooldown(User $user, string $phone): void
    {
        $verifiedAt = $user->phone_verified_at;

        if ($verifiedAt === null || $user->phone === $phone) {
            return;
        }

        $days = (int) config('qasa.phone_verification.change_cooldown_days', 3);

        if ($days <= 0) {
            return;
        }

        $allowedFrom = $verifiedAt->copy()->addDays($days);

        if ($allowedFrom->isFuture()) {
            throw DomainException::validation(__('auth.phone_change_too_soon', [
                'days' => (int) ceil(now()->diffInDays($allowedFrom, false)),
            ]));
        }
    }

    /**
     * One verified number, one account. Checked again at confirm time — two
     * accounts can both hold a live code for the same number, and only the
     * second confirm can see that it lost the race.
     */
    private function guardNotTakenElsewhere(User $user, string $phone): void
    {
        $owner = AccountLookup::byPhone($phone);

        if ($owner !== null && $owner !== $user->id) {
            throw DomainException::validation(__('auth.phone_already_taken'));
        }
    }

    private function guardCooldown(User $user): void
    {
        $cooldown = (int) config('qasa.phone_verification.resend_cooldown_seconds', 60);

        if ($cooldown <= 0) {
            return;
        }

        $last = PhoneVerificationCode::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        if ($last?->created_at === null) {
            return;
        }

        $elapsed = $last->created_at->diffInSeconds(now());

        if ($elapsed < $cooldown) {
            throw DomainException::validation(__('auth.phone_resend_too_soon', [
                'seconds' => (int) ceil($cooldown - $elapsed),
            ]));
        }
    }
}
