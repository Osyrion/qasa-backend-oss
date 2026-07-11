<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Services;

use App\Modules\Calendar\Domain\Models\Event;
use League\Csv\ByteSequence;
use League\Csv\Writer;

/**
 * Canonical QASA event CSV format — headers are fixed English keys (not
 * locale-translated) so an export can always be re-imported unchanged,
 * regardless of the exporting/importing user's locale.
 */
final class EventCsvBuilder
{
    private const COLUMNS = ['title', 'description', 'location', 'color', 'is_all_day', 'starts_at', 'ends_at'];

    /**
     * @param  iterable<Event>  $events
     */
    public function build(iterable $events): string
    {
        $writer = Writer::createFromString('');
        $writer->setDelimiter(';');
        $writer->setOutputBOM(ByteSequence::BOM_UTF8);

        $writer->insertOne(self::COLUMNS);

        foreach ($events as $event) {
            $writer->insertOne($this->row($event));
        }

        return $writer->toString();
    }

    /**
     * @return list<string>
     */
    private function row(Event $event): array
    {
        return [
            $event->title,
            $event->description ?? '',
            $event->location ?? '',
            $event->color ?? '',
            $event->is_all_day ? '1' : '0',
            $event->starts_at->format('Y-m-d H:i'),
            $event->ends_at->format('Y-m-d H:i'),
        ];
    }
}
