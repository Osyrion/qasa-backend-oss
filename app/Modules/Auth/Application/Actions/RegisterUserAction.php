<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\RegisterUserData;
use App\Modules\Auth\Domain\Events\TermsAccepted;
use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Application\Contracts\CaptchaVerifierInterface;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegisterUserAction
{
    public function __construct(
        private readonly CaptchaVerifierInterface $captchaVerifier,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(RegisterUserData $data, ?string $remoteIp = null): User
    {
        // Before the transaction, and before anything is written: the same
        // call the waitlist form makes. TurnstileVerifier returns true
        // outright when services.turnstile.enabled is off, so a deployment
        // that has not configured Turnstile is unaffected by this.
        if (! $this->captchaVerifier->verify($data->turnstile_token, $remoteIp)) {
            throw DomainException::validation(__('shared.waitlist.captcha_failed'));
        }

        $user = DB::transaction(function () use ($data): User {
            /** @var class-string<User> $model */
            $model = config('auth.providers.users.model', User::class);

            // Registration is unauthenticated, so nothing has bound the
            // connection yet — and users is now tenant-scoped too, so the
            // insert itself needs an account bound to satisfy WITH CHECK.
            // The row about to be created is its own account, so the id is
            // generated here instead of left to HasUuids, and bound before
            // the insert rather than after.
            //
            // forceCreate(), not create(): id isn't fillable, so a plain
            // create() silently drops it and HasUuids generates a fresh one
            // that was never bound — a factory sidesteps this by building
            // unguarded, but this isn't one.
            $id = (string) (new $model)->newUniqueId();
            TenantContext::set($id);

            $user = $model::query()->forceCreate([
                'id' => $id,
                'title' => $data->title,
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'password' => $data->password,
                'default_currency' => $data->default_currency,
                'locale' => $data->locale,
                'is_vat_payer' => false,
                'tax_flat_rate' => 0,
                // Stamped from config, never from the request — accepted_terms
                // only proves the checkbox was ticked, not which version was shown.
                'terms_accepted_at' => now(),
                'terms_version' => config('gdpr.terms_version'),
            ]);

            // The listeners below write rows that belong to the account this
            // very statement created: an activity entry, a VAT rate catalog.
            // Under the tenant policies those inserts are refused outright
            // without the binding above.

            // Self-registered users own their account — the SaaS Team module
            // assigns the Owner role via a listener on this event.
            event(new UserRegistered($user));
            event(new TermsAccepted($user, (string) $user->terms_version));

            return $user;
        });

        // Outside the transaction — a mail failure must not break registration.
        rescue(fn () => $user->sendEmailVerificationNotification());

        return $user;
    }
}
