<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

it('points the verification e-mail at the frontend, which forwards the signed API url', function (): void {
    config()->set('qasa.features.registration', true);
    config()->set('app.frontend_url', 'https://app.example.com');
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'jan@example.com',
        'password' => 'super-secret-1',
    ])->assertCreated();

    $user = userModel()::query()->where('email', 'jan@example.com')->firstOrFail();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $frontendUrl = $mail->actionUrl;

        expect($frontendUrl)->toStartWith('https://app.example.com/verify-email?url=');

        $apiUrl = urldecode(str($frontendUrl)->after('?url=')->value());
        expect($apiUrl)->toContain('/api/v1/auth/email/verify/'.$user->getKey())
            ->and($apiUrl)->toContain('signature=');

        $this->getJson($apiUrl)->assertOk();
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

        return true;
    });
});
