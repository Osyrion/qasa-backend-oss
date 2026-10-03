<?php

declare(strict_types=1);

namespace App\Modules\Shared\Mail;

use App\Modules\Shared\Enums\EmailDocumentType;
use Symfony\Component\Mime\Email;

/**
 * How a mailable says "this message is about that document".
 *
 * Carried as Symfony metadata headers (`X-Metadata-*`), which Laravel's
 * Envelope already supports and every ESP transport understands — Postmark
 * turns them into message metadata and hands them back on its delivery
 * webhooks. Nothing ESP-specific appears in the core module as a result:
 * a mailable declares `metadata: TrackedMail::for(...)` and is done.
 *
 * Whether any of it is recorded is a premium concern. In the OSS build no
 * listener consumes these headers and they ride along harmlessly.
 */
final class TrackedMail
{
    // A wire contract, not branding: the provider echoes these keys back on
    // its webhooks, days after sending. Renaming one strands the delivery and
    // bounce events of every message already sent, so they keep the name the
    // first messages left with.
    public const DOCUMENT_TYPE = 'qasa_document_type';

    public const DOCUMENT_ID = 'qasa_document_id';

    public const ACCOUNT = 'qasa_account';

    /**
     * Envelope metadata for a document-bound message.
     *
     * @return array<string, string>
     */
    public static function for(EmailDocumentType $type, string $documentId, string $accountOwnerId): array
    {
        return [
            self::DOCUMENT_TYPE => $type->value,
            self::DOCUMENT_ID => $documentId,
            self::ACCOUNT => $accountOwnerId,
        ];
    }

    /**
     * Reads back what for() wrote, from a message about to be or just sent.
     *
     * Returns null unless all three are present — a partially stamped
     * message is not something to guess about, and an untracked message
     * (most of them) simply has none of them.
     *
     * @return array{type: EmailDocumentType, id: string, account: string}|null
     */
    public static function read(Email $message): ?array
    {
        $headers = $message->getHeaders();

        $type = EmailDocumentType::tryFrom((string) $headers->getHeaderBody('X-Metadata-'.self::DOCUMENT_TYPE));
        $id = (string) $headers->getHeaderBody('X-Metadata-'.self::DOCUMENT_ID);
        $account = (string) $headers->getHeaderBody('X-Metadata-'.self::ACCOUNT);

        if ($type === null || $id === '' || $account === '') {
            return null;
        }

        return ['type' => $type, 'id' => $id, 'account' => $account];
    }
}
