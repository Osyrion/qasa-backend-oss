<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\DTOs;

use App\Modules\Shared\Enums\NotificationCategory;
use App\Modules\Shared\Enums\NotificationSeverity;

/**
 * The single shape every in-app notification stores in `data`.
 *
 * Without it each notification class invents its own keys and the front end
 * ends up switching on the notification FQCN to render a list row — which is
 * exactly the coupling a notification centre exists to avoid.
 *
 * `subjectType` is a Relation::morphMap alias ('invoice'), never an FQCN:
 * the payload is handed to a client, and a class name in it is both a leak
 * and a rename hazard. `actionUrl` is a front-end path, not an absolute URL,
 * because the API does not know which host the SPA is served from.
 */
final readonly class NotificationPayload
{
    public function __construct(
        public NotificationCategory $category,
        public NotificationSeverity $severity,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
        ];
    }
}
