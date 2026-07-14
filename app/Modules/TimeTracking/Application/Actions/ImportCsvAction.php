<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\Actions;

use App\Modules\TimeTracking\Application\Contracts\TimeEntryCsvParserInterface;
use App\Modules\TimeTracking\Application\Contracts\WorkLogRepositoryInterface;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use League\Csv\Exception;
use League\Csv\Reader;
use SplFileObject;

readonly class ImportCsvAction
{
    /**
     * @param  array<TimeEntryCsvParserInterface>  $parsers
     */
    public function __construct(
        private WorkLogRepositoryInterface $repository,
        private array $parsers,
    ) {}

    /**
     * Import time entries from a CSV file
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function execute(SplFileObject $file, string $userId, string $orderId): array
    {
        $csv = Reader::createFromFileObject($file);
        $csv->setHeaderOffset(0);

        $headers = array_values($csv->getHeader());
        $parser = $this->findParser($headers);

        if (! $parser) {
            throw new \InvalidArgumentException('Could not determine CSV format. Supported formats: Toggl, Clockify');
        }

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($csv as $row) {
            try {
                $timeEntryData = $parser->parseRow($row);

                // Check for duplicates
                if ($this->isDuplicate($userId, $timeEntryData)) {
                    $skipped++;

                    continue;
                }

                $timeEntryData['user_id'] = $userId;
                $timeEntryData['order_id'] = $orderId;

                $this->repository->create($timeEntryData);
                $created++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $row,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Find a suitable parser for the CSV headers
     *
     * @param  list<string>  $headers
     */
    private function findParser(array $headers): ?TimeEntryCsvParserInterface
    {
        return array_find($this->parsers, fn ($parser) => $parser->canHandle($headers));
    }

    /**
     * Check if a time entry already exists (duplicate check)
     *
     * @param  array<string, mixed>  $data
     */
    private function isDuplicate(string $userId, array $data): bool
    {
        return TimeEntry::query()
            ->where('user_id', $userId)
            ->where('external_id', $data['external_id'] ?? '')
            ->where('source', $data['source'] ?? '')
            ->exists();
    }
}
