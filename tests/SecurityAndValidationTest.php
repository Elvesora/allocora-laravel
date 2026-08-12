<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Tests;

use Elvesora\Allocora\Laravel\Client;
use Elvesora\Allocora\Laravel\Exceptions\ConfigurationException;
use Elvesora\Allocora\Laravel\Exceptions\InvalidInputException;
use Elvesora\Allocora\Laravel\Internal\Operation;
use Elvesora\Allocora\Laravel\Support\Transport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use TypeError;

final class SecurityAndValidationTest extends TestCase
{
    #[DataProvider('invalidApiKeys')]
    public function test_api_key_validation_rejects_unsafe_header_values(string $apiKey): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client(apiKey: $apiKey);
    }

    #[DataProvider('invalidBaseUrls')]
    public function test_base_url_is_restricted_to_the_public_https_v1_boundary(string $baseUrl): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client(baseUrl: $baseUrl);
    }

    public function test_timeouts_and_response_limit_are_positive_and_bounded(): void
    {
        $operations = [
            fn (): Client => $this->client(timeout: 0),
            fn (): Client => $this->client(timeout: 301),
            fn (): Client => $this->client(connectTimeout: 0),
            fn (): Client => $this->client(timeout: 4, connectTimeout: 5),
            fn (): Client => $this->client(maxResponseBytes: 1023),
            fn (): Client => $this->client(maxResponseBytes: 50_000_001),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Expected unsafe configuration to fail.');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_ids_payloads_batches_queries_and_sizes_fail_before_network_io(): void
    {
        Http::preventStrayRequests();
        $client = $this->client();
        $operations = [
            static fn () => $client->payees()->show('../invalid'),
            static fn () => $client->products()->archive('not-a-uuid'),
            static fn () => $client->payees()->create([['not' => 'an object']]),
            static fn () => $client->payees()->batchCreate([]),
            static fn () => $client->payees()->batchCreate([[]]),
            static fn () => $client->products()->batchCreate([[]]),
            static fn () => $client->revenues()->batchCreate([[]]),
            static fn () => $client->rules()->batchCreate([[]]),
            static fn () => $client->payees()->batchCreate(['not-an-object']),
            static fn () => $client->payees()->batchCreate(array_fill(0, 101, ['name' => 'One'])),
            static fn () => $client->payees()->batchUpdate([['name' => 'Missing id']]),
            static fn () => $client->products()->batchArchive([['id' => '../invalid']]),
            static fn () => $client->payees()->list(['bad name' => 'value']),
            static fn () => $client->payees()->list(['nested' => ['key' => 'value']]),
            static fn () => $client->payees()->list(['search' => str_repeat('q', 8_193)]),
            static fn () => $client->payees()->create(['notes' => str_repeat('x', 2_000_001)]),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Expected unsafe request input to fail.');
            } catch (InvalidInputException) {
                self::addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_transport_accepts_only_closed_manifest_operations_and_valid_identifier_usage(): void
    {
        Http::preventStrayRequests();
        $transport = new Transport(
            http: $this->app->make(Factory::class),
            apiKey: self::API_KEY,
            baseUrl: self::BASE_URL,
            timeout: 30,
            connectTimeout: 5,
            maxResponseBytes: 10_485_760,
        );

        $invalidOperations = [
            static fn () => $transport->request(Operation::PayeesShow),
            static fn () => $transport->request(Operation::PayeesIndex, resourceId: self::RESOURCE_ID),
        ];

        foreach ($invalidOperations as $operation) {
            try {
                $operation();
                self::fail('Expected invalid operation arguments to fail.');
            } catch (InvalidInputException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            $transport->request('GET');
            self::fail('Expected raw method/path dispatch to be impossible.');
        } catch (TypeError) {
            self::addToAssertionCount(1);
        }

        Http::assertNothingSent();
    }

    public function test_sensitive_parameter_and_public_client_boundary_are_explicit(): void
    {
        $constructor = new ReflectionMethod(Client::class, '__construct');
        $httpParameter = $constructor->getParameters()[0];
        $apiKeyParameter = $constructor->getParameters()[1];
        $transportConstructor = new ReflectionMethod(Transport::class, '__construct');
        $client = $this->client();

        self::assertNotEmpty($httpParameter->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($apiKeyParameter->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($transportConstructor->getParameters()[0]->getAttributes(\SensitiveParameter::class));
        self::assertFalse(method_exists($client, 'request'));
        self::assertSame(['api_key' => '[REDACTED]', 'version' => '0.1.0'], $client->__debugInfo());
    }

    public function test_credentials_do_not_leak_through_object_export(): void
    {
        $factorySecret = 'fictional_factory_secret_for_export_testing';
        $factoryPrivateUrl = 'https://private-factory.example.test/api/v1';
        $http = new Factory;
        $http->globalOptions([
            'base_uri' => $factoryPrivateUrl,
            'headers' => ['X-Fictional-Sibling-Key' => $factorySecret],
        ]);
        $client = new Client(
            http: $http,
            apiKey: self::API_KEY,
            baseUrl: self::BASE_URL,
        );
        $transport = new Transport(
            http: $http,
            apiKey: self::API_KEY,
            baseUrl: self::BASE_URL,
            timeout: 30,
            connectTimeout: 5,
            maxResponseBytes: 10_485_760,
        );

        foreach ([$client, $client->payees(), $client->products(), $client->revenues(), $client->rules(), $transport] as $value) {
            $export = var_export($value, true);
            $debug = print_r($value, true);

            self::assertStringNotContainsString(self::API_KEY, $export);
            self::assertStringNotContainsString(self::BASE_URL, $export);
            self::assertStringNotContainsString($factorySecret, $export);
            self::assertStringNotContainsString($factoryPrivateUrl, $export);
            self::assertStringNotContainsString(self::API_KEY, $debug);
            self::assertStringNotContainsString(self::BASE_URL, $debug);
            self::assertStringNotContainsString($factorySecret, $debug);
            self::assertStringNotContainsString($factoryPrivateUrl, $debug);
        }
    }

    public function test_configured_http_factory_is_redacted_from_invalid_client_configuration_traces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        $factorySecret = 'fictional_factory_secret_for_trace_testing';
        $factoryPrivateUrl = 'https://private-factory.example.test/api/v1';
        $http = new Factory;
        $http->globalOptions([
            'base_uri' => $factoryPrivateUrl,
            'headers' => ['X-Fictional-Sibling-Key' => $factorySecret],
        ]);

        try {
            new Client(
                http: $http,
                apiKey: self::API_KEY,
                baseUrl: self::BASE_URL,
                timeout: 0,
            );
            self::fail('Expected invalid client configuration to fail.');
        } catch (ConfigurationException $exception) {
            $trace = print_r($exception->getTrace(), true);

            self::assertStringNotContainsString($factorySecret, $trace);
            self::assertStringNotContainsString($factoryPrivateUrl, $trace);
            self::assertStringContainsString('SensitiveParameterValue', $trace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidApiKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['1234567'];
        yield 'space' => ['invalid key'];
        yield 'newline' => ["invalid\nkey"];
        yield 'non-ascii' => ['cle-secrete-avec-accent-é'];
        yield 'oversized' => [str_repeat('a', 4097)];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBaseUrls(): iterable
    {
        yield 'http' => ['http://api.example.test/api/v1'];
        yield 'wrong path' => ['https://api.example.test/api'];
        yield 'root only' => ['https://api.example.test'];
        yield 'trailing slash' => ['https://api.example.test/api/v1/'];
        yield 'credentials' => ['https://user:password@api.example.test/api/v1'];
        yield 'username only' => ['https://user@api.example.test/api/v1'];
        yield 'query' => ['https://api.example.test/api/v1?tenant=one'];
        yield 'fragment' => ['https://api.example.test/api/v1#fragment'];
        yield 'surrounding whitespace' => [' https://api.example.test/api/v1'];
    }

    private function client(
        string $apiKey = self::API_KEY,
        string $baseUrl = self::BASE_URL,
        float $timeout = 30,
        float $connectTimeout = 5,
        int $maxResponseBytes = 10_485_760,
    ): Client {
        return new Client(
            http: $this->app->make(Factory::class),
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            maxResponseBytes: $maxResponseBytes,
        );
    }
}
