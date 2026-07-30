<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Carries the account a job was dispatched for into the worker that runs it.
 *
 * A worker has no request and no authenticated user, so nothing binds its
 * connection — and under Row Level Security an unbound connection sees no
 * rows at all. That is not a theoretical problem: SerializesModels re-reads
 * every model a job carries as it deserialises, so a queued InvoiceEmail dies
 * on ModelNotFoundException for an invoice that is sitting right there.
 *
 * The whole suite still passed, because tests run the sync driver inside a
 * request that is already bound. Only a real worker ever runs unbound.
 *
 * So the account is written into the payload at dispatch, where it is known,
 * and read back before the job is handed the payload. A job dispatched with
 * nothing bound stays unbound and sees nothing, rather than inheriting
 * whoever the worker ran last.
 */
final class TenantQueue
{
    public const PAYLOAD_KEY = 'tenantAccountId';

    /**
     * What was bound before each in-flight job, keyed by job.
     *
     * @var array<string, string|null>
     */
    private static array $suspended = [];

    public static function listen(): void
    {
        Queue::createPayloadUsing(fn (): array => [self::PAYLOAD_KEY => TenantContext::current()]);

        Event::listen(fn (JobProcessing $event) => self::bind($event->job));

        // A job that throws never raises JobProcessed. Releasing on all three
        // outcomes is what keeps the parked value from outliving the job that
        // parked it — and the key makes the second of JobFailed and
        // JobExceptionOccurred a no-op rather than a second restore.
        Event::listen(fn (JobProcessed $event) => self::release($event->job));
        Event::listen(fn (JobFailed $event) => self::release($event->job));
        Event::listen(fn (JobExceptionOccurred $event) => self::release($event->job));
    }

    private static function bind(Job $job): void
    {
        self::$suspended[self::key($job)] = TenantContext::current();

        /** @var mixed $account */
        $account = $job->payload()[self::PAYLOAD_KEY] ?? null;

        if (is_string($account) && $account !== '') {
            TenantContext::set($account);

            return;
        }

        TenantContext::clear();
    }

    private static function release(Job $job): void
    {
        $key = self::key($job);

        if (! array_key_exists($key, self::$suspended)) {
            return;
        }

        $previous = self::$suspended[$key];
        unset(self::$suspended[$key]);

        $previous === null ? TenantContext::clear() : TenantContext::set($previous);
    }

    /**
     * Jobs pushed raw carry no uuid, and dispatchSync nests one job inside
     * another, so identity has to come from the job itself.
     */
    private static function key(Job $job): string
    {
        return $job->uuid() ?? 'object:'.spl_object_id($job);
    }
}
