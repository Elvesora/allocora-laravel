<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Resources;

use Elvesora\Allocora\Laravel\ApiResponse;
use Elvesora\Allocora\Laravel\Internal\Operation;

final class RulesResource extends AbstractResource
{
    /** @param array<string, mixed> $query */
    public function list(#[\SensitiveParameter] array $query = []): ApiResponse
    {
        return $this->transport->request(Operation::RulesIndex, query: $query);
    }

    public function show(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->transport->request(Operation::RulesShow, resourceId: $id);
    }

    /** @param array<string, mixed> $payload */
    public function create(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::RulesStore, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchCreate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::RulesBatchStore, payload: $this->batchPayload($records));
    }

    /** @param array<string, mixed> $payload */
    public function upsert(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::RulesUpsert, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchUpsert(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::RulesBatchUpsert, payload: $this->batchPayload($records));
    }

    /** @param array<string, mixed> $payload */
    public function update(#[\SensitiveParameter] string $id, #[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->transport->request(Operation::RulesUpdate, resourceId: $id, payload: $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchUpdate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->transport->request(Operation::RulesBatchUpdate, payload: $this->batchPayload($records, requireId: true));
    }

    public function toggleStatus(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->transport->request(Operation::RulesToggleStatus, resourceId: $id);
    }

    public function versions(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->transport->request(Operation::RulesVersions, resourceId: $id);
    }
}
