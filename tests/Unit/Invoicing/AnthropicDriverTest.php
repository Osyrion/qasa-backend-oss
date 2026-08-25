<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Domain\Exceptions\LlmExtractionException;
use App\Modules\Invoicing\Infrastructure\Ocr\Providers\AnthropicDriver;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function anthropicToolUseResponse(array $input): array
{
    return [
        'content' => [
            ['type' => 'tool_use', 'name' => 'extract_invoice_fields', 'id' => 'toolu_1', 'input' => $input],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function anthropicSchema(): array
{
    return ['type' => 'object', 'properties' => ['ico' => ['type' => ['string', 'null']]], 'required' => []];
}

it('reports the anthropic provider', function (): void {
    expect((new AnthropicDriver)->provider())->toBe(AiProvider::Anthropic);
});

it('parses a successful tool_use response into fields', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(anthropicToolUseResponse(['ico' => '12345678', 'total' => 120.5])),
    ]);

    $fields = (new AnthropicDriver)->extractJson([['type' => 'text', 'text' => 'x']], anthropicSchema(), 'sk-ant-test');

    expect($fields['ico'])->toBe('12345678')
        ->and($fields['total'])->toBe(120.5);
});

it('sends the key in the x-api-key header, never in the body', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicToolUseResponse(['ico' => '12345678']))]);

    (new AnthropicDriver)->extractJson([['type' => 'text', 'text' => 'x']], anthropicSchema(), 'sk-ant-secret');

    Http::assertSent(function ($request): bool {
        $body = json_encode($request->data());

        return $request->hasHeader('x-api-key', 'sk-ant-secret')
            && $body !== false && ! str_contains($body, 'sk-ant-secret');
    });
});

it('throws an auth error on 401', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

    try {
        (new AnthropicDriver)->extractJson([['type' => 'text', 'text' => 'x']], anthropicSchema(), 'bad-key');
        expect(false)->toBeTrue('expected LlmExtractionException');
    } catch (LlmExtractionException $e) {
        expect($e->isAuthError())->toBeTrue();
    }
});

it('throws a non-auth error on a malformed response', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);

    try {
        (new AnthropicDriver)->extractJson([['type' => 'text', 'text' => 'x']], anthropicSchema(), 'key');
        expect(false)->toBeTrue('expected LlmExtractionException');
    } catch (LlmExtractionException $e) {
        expect($e->isAuthError())->toBeFalse();
    }
});

it('throws on a 500 server error', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

    expect(fn () => (new AnthropicDriver)->extractJson([['type' => 'text', 'text' => 'x']], anthropicSchema(), 'key'))
        ->toThrow(LlmExtractionException::class);
});

it('throws on a connection timeout', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => (new AnthropicDriver)->extractJson([['type' => 'text', 'text' => 'x']], anthropicSchema(), 'key'))
        ->toThrow(LlmExtractionException::class);
});

it('throws on a 429 rate limit', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'rate_limited'], 429)]);

    try {
        (new AnthropicDriver)->extractJson([['type' => 'text', 'text' => 'x']], anthropicSchema(), 'key');
        expect(false)->toBeTrue('expected LlmExtractionException');
    } catch (LlmExtractionException $e) {
        expect($e->isAuthError())->toBeFalse();
    }
});

it('verifies a working key', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);

    expect((new AnthropicDriver)->verifyKey('sk-ant-valid'))->toBeTrue();
});

it('reports an invalid key as such without throwing', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

    expect((new AnthropicDriver)->verifyKey('sk-ant-invalid'))->toBeFalse();
});

it('throws a DomainException when the key test cannot reach the provider', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => (new AnthropicDriver)->verifyKey('sk-ant-any'))->toThrow(DomainException::class);
});
