<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Resources;

use Elvesora\Allocora\Laravel\ApiResponse;
use Elvesora\Allocora\Laravel\Internal\Operation;

final class ProductsResource extends AbstractResource
{
    /** @param array<string, mixed> $query */
    public function list(#[\SensitiveParameter] array $query = []): ApiResponse
    {
        return $this->transport->request(Operation::ProductsIndex, query: $query);
    }

    public function show(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->transport->request(Operation::ProductsShow, resourceId: $id);
    }

    /** @param array<string, mixed> $payload */
    public function create(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::ProductsStore, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchCreate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::ProductsBatchStore, payload: $this->batchPayload($records));
    }

    /** @param array<string, mixed> $payload */
    public function upsert(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::ProductsUpsert, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchUpsert(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::ProductsBatchUpsert, payload: $this->batchPayload($records));
    }

    /** @param array<string, mixed> $payload */
    public function update(#[\SensitiveParameter] string $id, #[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::ProductsUpdate, resourceId: $id, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchUpdate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::ProductsBatchUpdate, payload: $this->batchPayload($records, requireId: true));
    }

    public function archive(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->transport->request(Operation::ProductsDestroy, resourceId: $id);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchArchive(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::ProductsBatchDestroy, payload: $this->batchPayload($records, requireId: true));
    }
}
