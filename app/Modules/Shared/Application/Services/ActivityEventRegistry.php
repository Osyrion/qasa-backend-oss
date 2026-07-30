<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Services;

use App\Modules\Shared\Application\Listeners\RecordActivity;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps domain events onto activity-log entries.
 *
 * Each module registers its own events here, which does two things: it keeps
 * Shared from naming a premium event class (the OSS build would stop
 * compiling), and it replaces what used to be three parallel lists in
 * RecordActivity — event class, subject resolver and wire name — that had to
 * be kept in sync by hand.
 *
 * register() also wires the listener, so registration order across service
 * providers does not matter.
 */
final class ActivityEventRegistry
{
    /** @var array<class-string, string> */
    private array $wireNames = [];

    /** @var array<class-string, Closure> */
    private array $subjects = [];

    /** @var array<class-string, Closure> */
    private array $changes = [];

    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @template TEvent of object
     *
     * @param  class-string<TEvent>  $eventClass
     * @param  Closure(TEvent): ?Model  $subject
     * @param  Closure(TEvent): array<string, mixed>|null  $changes  Extra payload stored with the entry.
     */
    public function register(string $eventClass, string $wireName, Closure $subject, ?Closure $changes = null): void
    {
        $this->wireNames[$eventClass] = $wireName;
        $this->subjects[$eventClass] = $subject;

        if ($changes !== null) {
            $this->changes[$eventClass] = $changes;
        }

        $this->events->listen($eventClass, RecordActivity::class);
    }

    public function wireNameFor(object $event): ?string
    {
        return $this->wireNames[$event::class] ?? null;
    }

    public function subjectFor(object $event): ?Model
    {
        $resolver = $this->subjects[$event::class] ?? null;

        if ($resolver === null) {
            return null;
        }

        $subject = $resolver($event);

        return $subject instanceof Model ? $subject : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function changesFor(object $event): array
    {
        $resolver = $this->changes[$event::class] ?? null;

        if ($resolver === null) {
            return [];
        }

        /** @var array<string, mixed> $changes */
        $changes = (array) $resolver($event);

        return $changes;
    }
}
