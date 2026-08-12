<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Resources;

use Elvesora\Allocora\Laravel\ApiResponse;
use Elvesora\Allocora\Laravel\Internal\Operation;

final class PayeesResource extends AbstractResource
{
    /** @param array<string, mixed> $query */
    public function list(#[\SensitiveParameter] array $query = []): ApiResponse
    {
        return $this->transport->request(Operation::PayeesIndex, query: $query);
    }

    public function show(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->transport->request(Operation::PayeesShow, resourceId: $id);
    }

    /** @param array<string, mixed> $payload */
    public function create(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::PayeesStore, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchCreate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::PayeesBatchStore, payload: $this->batchPayload($records));
    }

    /** @param array<string, mixed> $payload */
    public function upsert(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::PayeesUpsert, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchUpsert(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::PayeesBatchUpsert, payload: $this->batchPayload($records));
    }

    /** @param array<string, mixed> $payload */
    public function update(#[\SensitiveParameter] string $id, #[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::PayeesUpdate, resourceId: $id, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchUpdate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::PayeesBatchUpdate, payload: $this->batchPayload($records, requireId: true));
    }
}
