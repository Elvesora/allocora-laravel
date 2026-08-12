<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Resources;

use Elvesora\Allocora\Laravel\ApiResponse;
use Elvesora\Allocora\Laravel\Internal\Operation;

final class RevenuesResource extends AbstractResource
{
    /** @param array<string, mixed> $query */
    public function list(#[\SensitiveParameter] array $query = []): ApiResponse
    {
        return $this->transport->request(Operation::RevenuesIndex, query: $query);
    }

    public function show(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->transport->request(Operation::RevenuesShow, resourceId: $id);
    }

    /** @param array<string, mixed> $payload */
    public function create(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::RevenuesStore, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchCreate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::RevenuesBatchStore, payload: $this->batchPayload($records));
    }
}
