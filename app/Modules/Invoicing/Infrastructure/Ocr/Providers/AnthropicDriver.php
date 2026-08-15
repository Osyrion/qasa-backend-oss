<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr\Providers;

use App\Modules\Invoicing\Domain\Contracts\LlmProviderDriver;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Infrastructure\Ocr\LlmExtractionException;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the Anthropic Messages API
 * (https://api.anthropic.com/v1/messages), forcing a single tool call so
 * extractJson() gets structured JSON back rather than free text to
 * re-parse.
 */
final class AnthropicDriver implements LlmProviderDriver
{
    private const string TOOL_NAME = 'extract_invoice_fields';

    public function provider(): AiProvider
    {
        return AiProvider::Anthropic;
    }

    public function extractJson(array $content, array $schema, string $apiKey): array
    {
        $response = $this->call($content, $schema, $apiKey);

        return $this->extractToolInput($response);
    }

    public function verifyKey(string $apiKey): bool
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(10)
                ->baseUrl('https://api.anthropic.com/v1')
                ->post('messages', [
                    'model' => $this->model(),
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]);
        } catch (ConnectionException) {
            throw DomainException::because(__('invoicing.ai_credential.test_unavailable'));
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return false;
        }

        if ($response->successful()) {
            return true;
        }

        throw DomainException::because(__('invoicing.ai_credential.test_unavailable'));
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     *
     * @throws LlmExtractionException
     */
    private function call(array $content, array $schema, string $apiKey): mixed
    {
        $timeout = (int) config('invoicing.inbox.extraction.providers.anthropic.timeout', 30);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout($timeout)
                ->baseUrl('https://api.anthropic.com/v1')
                ->post('messages', [
                    'model' => $this->model(),
                    'max_tokens' => (int) config('invoicing.inbox.extraction.providers.anthropic.max_tokens', 4000),
                    'messages' => [['role' => 'user', 'content' => $content]],
                    'tools' => [$this->toolDefinition($schema)],
                    'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
                ]);
        } catch (ConnectionException $e) {
            throw LlmExtractionException::because('Anthropic API connection failed: '.$e->getMessage());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw LlmExtractionException::unauthorized('Anthropic API rejected the key.');
        }

        try {
            $response->throw();
        } catch (RequestException $e) {
            throw LlmExtractionException::because('Anthropic API error: HTTP '.$response->status());
        } catch (Throwable $e) {
            throw LlmExtractionException::because('Anthropic API error: '.$e->getMessage());
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LlmExtractionException
     */
    private function extractToolInput(mixed $response): array
    {
        if (! is_array($response) || ! isset($response['content']) || ! is_array($response['content'])) {
            throw LlmExtractionException::because('Malformed Anthropic response: missing content.');
        }

        foreach ($response['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL_NAME) {
                /** @var array<string, mixed> $input */
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];

                return $input;
            }
        }

        throw LlmExtractionException::because('Malformed Anthropic response: no tool_use block.');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function toolDefinition(array $schema): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Report the fields found on a supplier invoice document. Use null for anything not present.',
            'input_schema' => $schema,
        ];
    }

    public function model(): string
    {
        return (string) config('invoicing.inbox.extraction.providers.anthropic.model', 'claude-haiku-4-5');
    }
}
