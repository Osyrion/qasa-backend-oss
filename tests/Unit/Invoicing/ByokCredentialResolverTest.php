<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\AiCredential;
use App\Modules\Invoicing\Infrastructure\Ocr\ByokCredentialResolver;

it('resolves null when the account has no BYOK credential', function (): void {
    $owner = createUser();

    expect((new ByokCredentialResolver)->forOwner($owner))->toBeNull();
});

it("resolves the account owner's credential", function (): void {
    $owner = createUser();
    $credential = AiCredential::factory()->for($owner)->create(['api_key' => 'sk-ant-byok']);

    $resolved = (new ByokCredentialResolver)->forOwner($owner);

    expect($resolved?->id)->toBe($credential->id)
        ->and($resolved?->api_key)->toBe('sk-ant-byok');
});

it('skips an unsupported provider in provider_priority and still resolves a later one', function (): void {
    config(['invoicing.inbox.extraction.provider_priority' => ['deepseek', 'anthropic']]);
    $owner = createUser();
    AiCredential::factory()->for($owner)->create(['api_key' => 'sk-ant-byok']);

    expect((new ByokCredentialResolver)->forOwner($owner)?->api_key)->toBe('sk-ant-byok');
});
