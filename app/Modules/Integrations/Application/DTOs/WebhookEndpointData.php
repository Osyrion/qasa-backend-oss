<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\DTOs;

use App\Modules\Integrations\Application\Webhooks\WebhookEventMap;
use App\Modules\Integrations\Domain\Rules\SafeWebhookUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class WebhookEndpointData extends Data
{
    /**
     * @param  list<string>  $events
     */
    public function __construct(
        public readonly string $url,
        public readonly array $events,
        public readonly bool $is_active = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url', new SafeWebhookUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEventMap::allWireEvents())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        /** @var list<string> $events */
        $events = $request->input('events', []);

        return new self(
            url: $request->string('url')->toString(),
            events: $events,
            is_active: $request->boolean('is_active', true),
        );
    }
}
