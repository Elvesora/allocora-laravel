<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Tests;

use Elvesora\Allocora\Laravel\ApiResponse;
use Elvesora\Allocora\Laravel\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ResourceContractTest extends TestCase
{
    /**
     * @param  array<string, mixed>|null  $expectedPayload
     */
    #[DataProvider('operationCases')]
    public function test_every_public_operation_maps_to_its_exact_api_route(
        string $routeName,
        string $permission,
        string $resource,
        string $operation,
        string $method,
        string $path,
        ?array $expectedPayload,
    ): void {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'data' => ['route' => $routeName],
                'meta' => ['permission' => $permission],
            ], 200, ['X-Request-Id' => 'request-123']),
        ]);

        $response = $this->invokeOperation($this->app->make(Client::class), $resource, $operation);

        self::assertSame(200, $response->status());
        self::assertSame(['route' => $routeName], $response->data());
        self::assertSame(['permission' => $permission], $response->meta());
        self::assertSame('request-123', $response->headerLine('X-Request-Id'));
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($method, $path, $resource, $operation, $expectedPayload): bool {
            self::assertSame($method, $request->method());
            self::assertSame('Bearer '.self::API_KEY, $request->header('Authorization')[0] ?? null);
            self::assertSame('application/json', $request->header('Accept')[0] ?? null);
            self::assertSame('identity', $request->header('Accept-Encoding')[0] ?? null);
            self::assertSame('elvesora-allocora-laravel/0.1.0', $request->header('User-Agent')[0] ?? null);

            $expectedUrl = self::BASE_URL.$path;
            if ($resource === 'revenues' && $operation === 'list') {
                $expectedUrl .= '?page=2&per_page=25';
            }

            self::assertSame($expectedUrl, $request->url());

            if ($expectedPayload === null) {
                self::assertSame('', $request->body());
            } else {
                self::assertSame($expectedPayload, json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR));
            }

            return true;
        });
    }

    public function test_response_exposes_an_immutable_generic_view(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['id' => 'one'], 'meta' => ['created' => true]], 201, [
                'X-RateLimit-Remaining' => '999',
            ]),
        ]);

        $response = $this->app->make(Client::class)->payees()->create(['name' => 'Ada']);

        self::assertInstanceOf(ApiResponse::class, $response);
        self::assertSame(201, $response->status());
        self::assertSame(['id' => 'one'], $response->data());
        self::assertSame(['created' => true], $response->meta());
        self::assertSame(['999'], $response->header('x-ratelimit-remaining'));
    }

    public function test_response_accessors_preserve_data_while_debug_export_and_serialization_are_safe(): void
    {
        $privateValue = 'fictional-customer-financial-value-for-response-testing';
        $privateHeader = 'fictional-private-response-header-for-testing';
        $response = new ApiResponse(
            status: 201,
            body: [
                'data' => ['financial_reference' => $privateValue],
                'meta' => ['processed' => 1],
            ],
            headers: [
                'X-Private-Reference' => [$privateHeader],
                'X-RateLimit-Remaining' => ['999'],
            ],
        );

        self::assertSame(201, $response->status());
        self::assertSame(['financial_reference' => $privateValue], $response->data());
        self::assertSame(['processed' => 1], $response->meta());
        self::assertSame([$privateHeader], $response->header('x-private-reference'));
        self::assertSame($privateHeader, $response->headerLine('X-Private-Reference'));
        self::assertSame([
            'data' => ['financial_reference' => $privateValue],
            'meta' => ['processed' => 1],
        ], $response->body());
        self::assertSame([
            'X-Private-Reference' => [$privateHeader],
            'X-RateLimit-Remaining' => ['999'],
        ], $response->headers());
        self::assertSame([
            'status' => 201,
            'body_field_count' => 2,
            'header_field_count' => 2,
        ], $response->__debugInfo());

        foreach ([print_r($response, true), var_export($response, true)] as $surface) {
            self::assertStringNotContainsString($privateValue, $surface);
            self::assertStringNotContainsString($privateHeader, $surface);
        }

        $this->expectException(LogicException::class);
        serialize($response);
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string, array<string, mixed>|null}>
     */
    public static function operationCases(): iterable
    {
        $payload = ['external_id' => 'customer-1'];
        $records = ['records' => [$payload]];
        $updatedRecords = ['records' => [['id' => self::RESOURCE_ID, 'name' => 'Updated']]];
        $archivedRecords = ['records' => [['id' => self::RESOURCE_ID]]];

        yield 'payees.list' => ['api.v1.payees.index', 'read:payees', 'payees', 'list', 'GET', '/payees', null];
        yield 'payees.show' => ['api.v1.payees.show', 'read:payees', 'payees', 'show', 'GET', '/payees/'.self::RESOURCE_ID, null];
        yield 'payees.batchCreate' => ['api.v1.payees.batch-store', 'write:payees', 'payees', 'batchCreate', 'POST', '/payees/batch', $records];
        yield 'payees.batchUpsert' => ['api.v1.payees.batch-upsert', 'write:payees', 'payees', 'batchUpsert', 'POST', '/payees/batch/upsert', $records];
        yield 'payees.create' => ['api.v1.payees.store', 'write:payees', 'payees', 'create', 'POST', '/payees', $payload];
        yield 'payees.upsert' => ['api.v1.payees.upsert', 'write:payees', 'payees', 'upsert', 'POST', '/payees/upsert', $payload];
        yield 'payees.batchUpdate' => ['api.v1.payees.batch-update', 'write:payees', 'payees', 'batchUpdate', 'PUT', '/payees/batch', $updatedRecords];
        yield 'payees.update' => ['api.v1.payees.update', 'write:payees', 'payees', 'update', 'PUT', '/payees/'.self::RESOURCE_ID, $payload];

        yield 'products.list' => ['api.v1.products.index', 'read:products', 'products', 'list', 'GET', '/products', null];
        yield 'products.show' => ['api.v1.products.show', 'read:products', 'products', 'show', 'GET', '/products/'.self::RESOURCE_ID, null];
        yield 'products.batchCreate' => ['api.v1.products.batch-store', 'write:products', 'products', 'batchCreate', 'POST', '/products/batch', $records];
        yield 'products.batchUpsert' => ['api.v1.products.batch-upsert', 'write:products', 'products', 'batchUpsert', 'POST', '/products/batch/upsert', $records];
        yield 'products.create' => ['api.v1.products.store', 'write:products', 'products', 'create', 'POST', '/products', $payload];
        yield 'products.upsert' => ['api.v1.products.upsert', 'write:products', 'products', 'upsert', 'POST', '/products/upsert', $payload];
        yield 'products.batchUpdate' => ['api.v1.products.batch-update', 'write:products', 'products', 'batchUpdate', 'PUT', '/products/batch', $updatedRecords];
        yield 'products.update' => ['api.v1.products.update', 'write:products', 'products', 'update', 'PUT', '/products/'.self::RESOURCE_ID, $payload];
        yield 'products.batchArchive' => ['api.v1.products.batch-destroy', 'write:products', 'products', 'batchArchive', 'DELETE', '/products/batch', $archivedRecords];
        yield 'products.archive' => ['api.v1.products.destroy', 'write:products', 'products', 'archive', 'DELETE', '/products/'.self::RESOURCE_ID, null];

        yield 'revenues.list' => ['api.v1.revenues.index', 'read:revenues', 'revenues', 'list', 'GET', '/revenues', null];
        yield 'revenues.show' => ['api.v1.revenues.show', 'read:revenues', 'revenues', 'show', 'GET', '/revenues/'.self::RESOURCE_ID, null];
        yield 'revenues.batchCreate' => ['api.v1.revenues.batch-store', 'write:revenues', 'revenues', 'batchCreate', 'POST', '/revenues/batch', $records];
        yield 'revenues.create' => ['api.v1.revenues.store', 'write:revenues', 'revenues', 'create', 'POST', '/revenues', $payload];

        yield 'rules.list' => ['api.v1.rules.index', 'read:rules', 'rules', 'list', 'GET', '/rules', null];
        yield 'rules.show' => ['api.v1.rules.show', 'read:rules', 'rules', 'show', 'GET', '/rules/'.self::RESOURCE_ID, null];
        yield 'rules.batchCreate' => ['api.v1.rules.batch-store', 'write:rules', 'rules', 'batchCreate', 'POST', '/rules/batch', $records];
        yield 'rules.batchUpsert' => ['api.v1.rules.batch-upsert', 'write:rules', 'rules', 'batchUpsert', 'POST', '/rules/batch/upsert', $records];
        yield 'rules.create' => ['api.v1.rules.store', 'write:rules', 'rules', 'create', 'POST', '/rules', $payload];
        yield 'rules.upsert' => ['api.v1.rules.upsert', 'write:rules', 'rules', 'upsert', 'POST', '/rules/upsert', $payload];
        yield 'rules.batchUpdate' => ['api.v1.rules.batch-update', 'write:rules', 'rules', 'batchUpdate', 'PUT', '/rules/batch', $updatedRecords];
        yield 'rules.update' => ['api.v1.rules.update', 'write:rules', 'rules', 'update', 'PUT', '/rules/'.self::RESOURCE_ID, $payload];
        yield 'rules.toggleStatus' => ['api.v1.rules.toggle-status', 'write:rules', 'rules', 'toggleStatus', 'POST', '/rules/'.self::RESOURCE_ID.'/toggle-status', null];
        yield 'rules.versions' => ['api.v1.rules.versions', 'read:rules', 'rules', 'versions', 'GET', '/rules/'.self::RESOURCE_ID.'/versions', null];
    }

    private function invokeOperation(Client $client, string $resource, string $operation): ApiResponse
    {
        $resourceClient = match ($resource) {
            'payees' => $client->payees(),
            'products' => $client->products(),
            'revenues' => $client->revenues(),
            'rules' => $client->rules(),
        };

        $payload = ['external_id' => 'customer-1'];

        return match ($operation) {
            'list' => $resource === 'revenues'
                ? $resourceClient->list(['page' => 2, 'per_page' => 25])
                : $resourceClient->list(),
            'show' => $resourceClient->show(self::RESOURCE_ID),
            'create' => $resourceClient->create($payload),
            'batchCreate' => $resourceClient->batchCreate([$payload]),
            'upsert' => $resourceClient->upsert($payload),
            'batchUpsert' => $resourceClient->batchUpsert([$payload]),
            'update' => $resourceClient->update(self::RESOURCE_ID, $payload),
            'batchUpdate' => $resourceClient->batchUpdate([['id' => self::RESOURCE_ID, 'name' => 'Updated']]),
            'archive' => $resourceClient->archive(self::RESOURCE_ID),
            'batchArchive' => $resourceClient->batchArchive([['id' => self::RESOURCE_ID]]),
            'toggleStatus' => $resourceClient->toggleStatus(self::RESOURCE_ID),
            'versions' => $resourceClient->versions(self::RESOURCE_ID),
        };
    }
}
