<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Resources;

use Elvesora\Allocora\Laravel\Exceptions\InvalidInputException;
use Elvesora\Allocora\Laravel\Support\Transport;

abstract class AbstractResource
{
    public function __construct(protected readonly Transport $transport) {}

    /**
     * @param  array<array-key, mixed>  $records
     * @return array{records: list<array<string, mixed>>}
     */
    protected function batchPayload(#[\SensitiveParameter] array $records, bool $requireId = false): array
    {
        if (! array_is_list($records) || count($records) < 1 || count($records) > 100) {
            throw new InvalidInputException('Batch requests require a list containing between 1 and 100 records.');
        }

        $validatedRecords = [];

        foreach ($records as $record) {
            if (! is_array($record) || $record === [] || array_is_list($record)) {
                throw new InvalidInputException('Each batch record must be a non-empty keyed JSON object.');
            }

            $validatedRecord = [];
            foreach ($record as $key => $value) {
                if (! is_string($key)) {
                    throw new InvalidInputException('Each batch record must use string field names.');
                }

                $validatedRecord[$key] = $value;
            }

            if ($requireId) {
                $id = $validatedRecord['id'] ?? null;

                if (! is_string($id)) {
                    throw new InvalidInputException('Every batch update or archive record must contain a UUID id.');
                }

                $this->transport->identifier($id);
            }

            $validatedRecords[] = $validatedRecord;
        }

        return ['records' => $validatedRecords];
    }
}
