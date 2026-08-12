<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Tests;

use Elvesora\Allocora\Laravel\Internal\Operation;

final class ContractManifestTest extends TestCase
{
    public function test_manifest_is_the_exact_32_operation_public_contract(): void
    {
        $contents = file_get_contents(dirname(__DIR__).'/resources/public-api-manifest.json');
        self::assertIsString($contents);

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['schema_version']);
        self::assertSame('v1', $manifest['api_version']);
        self::assertSame('/api/v1', $manifest['base_path']);
        self::assertSame('bearer', $manifest['authentication']);
        self::assertSame(['max_attempts' => 1000, 'window_seconds' => 60], $manifest['rate_limit']);

        $operations = $manifest['operations'];
        self::assertIsArray($operations);
        self::assertCount(32, $operations);

        $manifestOperations = [];
        foreach ($operations as $operation) {
            self::assertIsArray($operation);
            self::assertSame(['name', 'method', 'path', 'permission'], array_keys($operation));
            self::assertStringStartsWith('api.v1.', $operation['name']);
            self::assertStringStartsWith('/api/v1/', $operation['path']);
            self::assertContains($operation['method'], ['GET', 'POST', 'PUT', 'DELETE']);
            self::assertMatchesRegularExpression('/\A(read|write):(payees|products|revenues|rules)\z/D', $operation['permission']);

            $manifestOperations[$operation['name']] = [
                $operation['method'],
                $operation['path'],
                $operation['permission'],
            ];
        }

        self::assertCount(32, $manifestOperations, 'Manifest operation names must be unique.');

        $typedOperations = [];
        foreach (Operation::cases() as $operation) {
            $typedOperations[$operation->routeName()] = [
                $operation->method(),
                '/api/v1'.$operation->path(),
                $operation->permission(),
            ];
        }

        self::assertCount(32, $typedOperations);
        self::assertSame($manifestOperations, $typedOperations);

        $sdkOperations = [];
        foreach (ResourceContractTest::operationCases() as $case) {
            [$name, $permission, , , $method, $path] = $case;
            $sdkOperations[$name] = [
                $method,
                str_replace(self::RESOURCE_ID, '{id}', '/api/v1'.$path),
                $permission,
            ];
        }

        self::assertSame($manifestOperations, $sdkOperations);
    }
}
