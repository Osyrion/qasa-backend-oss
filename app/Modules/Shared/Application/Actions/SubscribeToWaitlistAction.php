<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Application\Contracts\CaptchaVerifierInterface;
use App\Modules\Shared\Application\DTOs\WaitlistSignupData;
use App\Modules\Shared\Domain\Models\WaitlistSignup;
use App\Modules\Shared\Exceptions\DomainException;

class SubscribeToWaitlistAction
{
    public function __construct(
        private readonly CaptchaVerifierInterface $captchaVerifier,
    ) {}

    public function execute(WaitlistSignupData $data, ?string $remoteIp = null): void
    {
        // A filled honeypot means a bot, not a visitor — do nothing, but
        // still let the controller respond as if it succeeded. Checked
        // before the captcha call so a bot never costs a Turnstile request.
        if ($data->honeypot !== null && $data->honeypot !== '') {
            return;
        }

        // Unlike the honeypot above, a captcha failure surfaces as a real
        // 422 — a genuine visitor whose widget failed to load needs to see
        // that and retry, where the honeypot's silent success is only safe
        // because it's invisible to a real visitor in the first place.
        if (! $this->captchaVerifier->verify($data->turnstileToken, $remoteIp)) {
            throw DomainException::validation(__('shared.waitlist.captcha_failed'));
        }

        // firstOrCreate: resubmitting an address already on the list is not
        // an error, and the response must not differ from a first-time
        // signup — otherwise the endpoint becomes an e-mail-enumeration oracle.
        WaitlistSignup::query()->firstOrCreate(
            ['email' => $data->email],
            ['locale' => $data->locale, 'page_variant' => $data->pageVariant],
        );
    }
}
