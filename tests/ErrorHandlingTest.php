<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Tests;

use Elvesora\Allocora\Laravel\Client;
use Elvesora\Allocora\Laravel\Exceptions\ApiException;
use Elvesora\Allocora\Laravel\Exceptions\AuthenticationException;
use Elvesora\Allocora\Laravel\Exceptions\AuthorizationException;
use Elvesora\Allocora\Laravel\Exceptions\InvalidResponseException;
use Elvesora\Allocora\Laravel\Exceptions\NotFoundException;
use Elvesora\Allocora\Laravel\Exceptions\RateLimitException;
use Elvesora\Allocora\Laravel\Exceptions\ServerException;
use Elvesora\Allocora\Laravel\Exceptions\TransportException;
use Elvesora\Allocora\Laravel\Exceptions\ValidationException;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

final class ErrorHandlingTest extends TestCase
{
    /** @param class-string<ApiException> $expectedClass */
    #[DataProvider('httpErrorCases')]
    public function test_http_errors_are_typed_sanitized_and_never_retried(
        int $status,
        string $expectedClass,
        string $expectedMessage,
        string $expectedErrorCode,
    ): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'message' => 'Server echoed Bearer '.self::API_KEY,
                'code' => 'API_FAILURE',
            ], $status, [
                'Retry-After' => '17',
                'X-Request-ID' => 'request-123',
            ]),
        ]);

        try {
            try {
                $this->app->make(Client::class)->payees()->list();
                self::fail('Expected a typed API exception.');
            } catch (ApiException $exception) {
                self::assertInstanceOf($expectedClass, $exception);
                self::assertSame($status, $exception->statusCode);
                self::assertSame($expectedMessage, $exception->getMessage());
                self::assertSame($expectedErrorCode, $exception->errorCode);
                self::assertSame(17, $exception->retryAfterSeconds);
                self::assertSame('request-123', $exception->requestId);
                self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
                self::assertStringNotContainsString(self::API_KEY, print_r($exception, true));
                self::assertStringNotContainsString(self::API_KEY, print_r($this->sdkTrace($exception), true));
                Http::assertSentCount(1);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_upstream_error_details_never_escape_exception_surfaces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);

        try {
            foreach (self::sensitiveUpstreamErrorCases() as [$status, $errorField, $upstreamDetails, $expectedMessage, $expectedErrorCode, $sensitiveFragments]) {
                $http = new Factory;
                $http->preventStrayRequests();
                $http->fake([
                    '*' => $http->response([
                        $errorField => $upstreamDetails,
                        'code' => 'DATABASE_PASSWORD_EXPOSED',
                        'error_code' => 'AWS_SECRET_ACCESS_KEY',
                    ], $status, [
                        'Content-Type' => 'application/json',
                        'Retry-After' => '31',
                        'X-Request-ID' => 'safe-request-456',
                    ]),
                ]);
                $client = new Client(
                    http: $http,
                    apiKey: self::API_KEY,
                    baseUrl: self::BASE_URL,
                );

                try {
                    $client->payees()->list();
                    self::fail('Expected a typed API exception.');
                } catch (ApiException $exception) {
                    $surfaces = [
                        $exception->getMessage(),
                        (string) $exception,
                        print_r($exception, true),
                        var_export($exception->__debugInfo(), true),
                        print_r($exception->getTrace(), true),
                    ];

                    try {
                        $surfaces[] = serialize($exception);
                    } catch (Throwable $serializationFailure) {
                        self::assertStringNotContainsString($upstreamDetails, (string) $serializationFailure);
                        self::assertStringNotContainsString('DATABASE_PASSWORD_EXPOSED', (string) $serializationFailure);
                        self::assertStringNotContainsString('AWS_SECRET_ACCESS_KEY', (string) $serializationFailure);
                    }

                    self::assertSame($expectedMessage, $exception->getMessage());
                    self::assertSame($expectedErrorCode, $exception->errorCode);
                    self::assertSame(31, $exception->retryAfterSeconds);
                    self::assertSame('safe-request-456', $exception->requestId);
                    self::assertNull($exception->getPrevious());

                    foreach ($surfaces as $surface) {
                        self::assertStringNotContainsString($upstreamDetails, $surface);
                        self::assertStringNotContainsString('DATABASE_PASSWORD_EXPOSED', $surface);
                        self::assertStringNotContainsString('AWS_SECRET_ACCESS_KEY', $surface);

                        foreach ($sensitiveFragments as $sensitiveFragment) {
                            self::assertStringNotContainsString($sensitiveFragment, $surface);
                        }
                    }

                    $http->assertSentCount(1);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    #[DataProvider('invalidResponseCases')]
    public function test_invalid_responses_fail_closed(string $body, ?string $contentType): void
    {
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];
        Http::fake(['*' => Http::response($body, 200, $headers)]);

        $this->expectException(InvalidResponseException::class);
        $this->app->make(Client::class)->payees()->list();
    }

    public function test_oversized_response_is_rejected(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 10_485_761), 200, ['Content-Type' => 'application/json'])]);

        $this->expectException(InvalidResponseException::class);
        $this->app->make(Client::class)->payees()->list();
    }

    /** @param class-string<ApiException> $expectedClass */
    #[DataProvider('oversizedErrorResponseCases')]
    public function test_oversized_error_responses_remain_status_typed(
        int $status,
        string $expectedClass,
        string $expectedMessage,
        string $expectedErrorCode,
    ): void {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        $this->app['config']->set('allocora.max_response_bytes', 1_024);
        $this->app->forgetScopedInstances();
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(str_repeat('x', 1_025).self::API_KEY, $status, [
                'Content-Type' => 'text/plain',
                'Retry-After' => '19',
                'X-Request-ID' => 'oversized-request-123',
            ]),
        ]);

        try {
            try {
                $this->app->make(Client::class)->payees()->list();
                self::fail('Expected an oversized error response to remain status-typed.');
            } catch (ApiException $exception) {
                self::assertInstanceOf($expectedClass, $exception);
                self::assertSame($status, $exception->statusCode);
                self::assertSame($expectedErrorCode, $exception->errorCode);
                self::assertSame(19, $exception->retryAfterSeconds);
                self::assertSame('oversized-request-123', $exception->requestId);
                self::assertSame($expectedMessage, $exception->getMessage());
                self::assertStringNotContainsString(self::API_KEY, print_r($exception, true));
                self::assertStringNotContainsString(self::API_KEY, print_r($this->sdkTrace($exception), true));
                Http::assertSentCount(1);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_oversized_error_rejected_from_headers_remains_status_typed(): void
    {
        $handler = new MockHandler([
            new PsrResponse(503, [
                'Content-Length' => '2048',
                'Retry-After' => '23',
                'X-Correlation-ID' => 'oversized-header-request-123',
            ]),
        ]);
        $http = new class($handler) extends Factory
        {
            public function __construct(private readonly MockHandler $handler)
            {
                parent::__construct();
            }

            public function createPendingRequest(): PendingRequest
            {
                return parent::createPendingRequest()->setHandler($this->handler);
            }
        };
        $client = new Client(
            http: $http,
            apiKey: self::API_KEY,
            baseUrl: self::BASE_URL,
            maxResponseBytes: 1_024,
        );

        try {
            $client->payees()->list();
            self::fail('Expected an oversized server response to remain status-typed.');
        } catch (ServerException $exception) {
            self::assertSame(503, $exception->statusCode);
            self::assertSame('SERVER_ERROR', $exception->errorCode);
            self::assertSame(23, $exception->retryAfterSeconds);
            self::assertSame('oversized-header-request-123', $exception->requestId);
            self::assertSame('The Allocora API is temporarily unavailable.', $exception->getMessage());
            self::assertStringNotContainsString(self::API_KEY, print_r($exception, true));
        }
    }

    public function test_transport_requests_identity_encoding_and_disables_transparent_decoding(): void
    {
        $handler = new MockHandler([
            new PsrResponse(200, ['Content-Type' => 'application/json'], '{"data":{}}'),
        ]);

        $this->clientWithHandler($handler)->payees()->list();

        $request = $handler->getLastRequest();
        $options = $handler->getLastOptions();

        self::assertNotNull($request);
        self::assertSame('identity', $request->getHeaderLine('Accept-Encoding'));
        self::assertArrayHasKey('decode_content', $options);
        self::assertFalse($options['decode_content']);
    }

    public function test_shared_factory_global_options_and_middleware_are_isolated_from_allocora_requests(): void
    {
        $globalCredential = 'fictional_unrelated_global_credential';
        $globalInternalValue = 'fictional_unrelated_internal_metadata';
        $globalOptionsEvaluated = false;
        $globalMiddlewareCalled = false;
        $handler = new MockHandler([
            new PsrResponse(200, ['Content-Type' => 'application/json'], '{"data":{}}'),
        ]);
        $http = $this->factoryWithHandler($handler);
        $http->globalOptions(static function () use (
            &$globalOptionsEvaluated,
            $globalCredential,
            $globalInternalValue,
        ): array {
            $globalOptionsEvaluated = true;

            return [
                'allow_redirects' => true,
                'auth' => ['global-user', $globalCredential],
                'body' => $globalInternalValue,
                'cookies' => CookieJar::fromArray(['global_session' => $globalCredential], 'api.example.test'),
                'decode_content' => 'gzip',
                'headers' => [
                    'Accept-Encoding' => 'gzip',
                    'Authorization' => 'Bearer '.$globalCredential,
                    'Cookie' => 'global_session='.$globalCredential,
                    'User-Agent' => 'unrelated-global-client/1.0',
                    'X-Internal-Context' => $globalInternalValue,
                ],
                'query' => ['global_context' => $globalInternalValue],
                'verify' => false,
            ];
        });
        $http->globalRequestMiddleware(static function (RequestInterface $request) use (
            &$globalMiddlewareCalled,
            $globalCredential,
            $globalInternalValue,
        ): RequestInterface {
            $globalMiddlewareCalled = true;

            return $request
                ->withHeader('Accept-Encoding', 'gzip')
                ->withHeader('Authorization', 'Bearer '.$globalCredential)
                ->withHeader('Cookie', 'global_session='.$globalCredential)
                ->withHeader('X-Internal-Context', $globalInternalValue);
        });

        $this->clientWithFactory($http)->revenues()->list(['page' => 2]);

        $request = $handler->getLastRequest();
        $options = $handler->getLastOptions();

        self::assertFalse($globalOptionsEvaluated);
        self::assertFalse($globalMiddlewareCalled);
        self::assertNotNull($request);
        self::assertSame(self::BASE_URL.'/revenues?page=2', (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(['application/json'], $request->getHeader('Accept'));
        self::assertSame(['identity'], $request->getHeader('Accept-Encoding'));
        self::assertSame(['Bearer '.self::API_KEY], $request->getHeader('Authorization'));
        self::assertSame(['elvesora-allocora-laravel/0.1.0'], $request->getHeader('User-Agent'));
        self::assertFalse($request->hasHeader('Cookie'));
        self::assertFalse($request->hasHeader('X-Internal-Context'));
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['decode_content']);
        self::assertTrue($options['verify']);
        self::assertArrayNotHasKey('auth', $options);
        self::assertArrayNotHasKey('query', $options);
    }

    public function test_factory_fakes_and_request_recording_remain_available_when_global_state_is_isolated(): void
    {
        $globalOptionsEvaluated = false;
        $globalMiddlewareCalled = false;
        $http = new Factory;
        $http->globalOptions(static function () use (&$globalOptionsEvaluated): array {
            $globalOptionsEvaluated = true;

            return ['headers' => ['X-Unrelated-Global' => 'fictional-global-value']];
        });
        $http->globalRequestMiddleware(static function (RequestInterface $request) use (&$globalMiddlewareCalled): RequestInterface {
            $globalMiddlewareCalled = true;

            return $request->withHeader('X-Unrelated-Middleware', 'fictional-middleware-value');
        });
        $http->preventStrayRequests();
        $http->fake([
            '*' => $http->response(['data' => ['fake' => true]], 200),
        ]);

        $response = $this->clientWithFactory($http)->payees()->list();

        self::assertSame(['fake' => true], $response->data());
        self::assertFalse($globalOptionsEvaluated);
        self::assertFalse($globalMiddlewareCalled);
        $http->assertSentCount(1);
        $http->assertSent(static fn (Request $request): bool => ! $request->hasHeader('X-Unrelated-Global')
            && ! $request->hasHeader('X-Unrelated-Middleware'));

        $http->get('https://unrelated.example.test/probe');

        self::assertTrue($globalOptionsEvaluated);
        self::assertTrue($globalMiddlewareCalled);
        $http->assertSentCount(2);
        $http->assertSent(static fn (Request $request): bool => $request->url() === 'https://unrelated.example.test/probe'
            && $request->hasHeader('X-Unrelated-Global', 'fictional-global-value')
            && $request->hasHeader('X-Unrelated-Middleware', 'fictional-middleware-value'));
    }

    public function test_compressed_success_is_rejected_before_its_body_is_read(): void
    {
        $bodyRead = false;
        $body = FnStream::decorate(Utils::streamFor('compressed-placeholder'), [
            '__toString' => static function () use (&$bodyRead): string {
                $bodyRead = true;

                return '';
            },
            'read' => static function (int $length) use (&$bodyRead): string {
                $bodyRead = true;

                return '';
            },
            'getContents' => static function () use (&$bodyRead): string {
                $bodyRead = true;

                return '';
            },
        ]);
        $handler = new MockHandler([
            new PsrResponse(200, [
                'Content-Type' => 'application/json',
                'Content-Encoding' => 'gzip',
            ], $body),
        ]);

        try {
            $this->clientWithHandler($handler)->payees()->list();
            self::fail('Expected a compressed successful response to fail closed.');
        } catch (InvalidResponseException $exception) {
            self::assertSame('Allocora returned an unsupported content encoding.', $exception->getMessage());
            self::assertFalse($bodyRead);
        }
    }

    public function test_compressed_error_response_remains_status_typed(): void
    {
        $handler = new MockHandler([
            new PsrResponse(503, [
                'Content-Encoding' => 'gzip',
                'Retry-After' => '29',
                'X-Request-ID' => 'compressed-error-request-123',
            ], 'compressed-error-placeholder'),
        ]);

        try {
            $this->clientWithHandler($handler)->payees()->list();
            self::fail('Expected a compressed error response to remain status-typed.');
        } catch (ServerException $exception) {
            self::assertSame(503, $exception->statusCode);
            self::assertSame('SERVER_ERROR', $exception->errorCode);
            self::assertSame(29, $exception->retryAfterSeconds);
            self::assertSame('compressed-error-request-123', $exception->requestId);
        }
    }

    public function test_oversized_error_metadata_headers_are_discarded_without_losing_status_type(): void
    {
        $handler = new MockHandler([
            new PsrResponse(429, [
                'Retry-After' => str_repeat('9', 129),
                'X-Request-ID' => str_repeat('r', 201),
                'X-Correlation-ID' => str_repeat('c', 201),
            ], 'ignored-error-body'),
        ]);

        try {
            $this->clientWithHandler($handler)->payees()->list();
            self::fail('Expected an error with oversized metadata to remain status-typed.');
        } catch (RateLimitException $exception) {
            self::assertSame(429, $exception->statusCode);
            self::assertSame('RATE_LIMIT_ERROR', $exception->errorCode);
            self::assertNull($exception->retryAfterSeconds);
            self::assertNull($exception->requestId);
        }
    }

    public function test_excessive_error_metadata_header_values_are_discarded(): void
    {
        $handler = new MockHandler([
            new PsrResponse(503, [
                'Retry-After' => array_fill(0, 11, ''),
                'X-Request-ID' => array_fill(0, 11, ''),
                'X-Correlation-ID' => array_fill(0, 11, ''),
            ], 'ignored-error-body'),
        ]);

        try {
            $this->clientWithHandler($handler)->payees()->list();
            self::fail('Expected an error with excessive metadata values to remain status-typed.');
        } catch (ServerException $exception) {
            self::assertSame(503, $exception->statusCode);
            self::assertNull($exception->retryAfterSeconds);
            self::assertNull($exception->requestId);
        }
    }

    /** @param array<string, string|list<string>> $headers */
    #[DataProvider('unsafeSuccessfulResponseHeaderCases')]
    public function test_successful_response_headers_are_bounded(array $headers): void
    {
        $handler = new MockHandler([
            new PsrResponse(200, ['Content-Type' => 'application/json', ...$headers], '{}'),
        ]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('The Allocora response headers exceeded the safety limits.');
        $this->clientWithHandler($handler)->payees()->list();
    }

    /** @param class-string<ApiException> $expectedClass */
    #[DataProvider('invalidErrorResponseCases')]
    public function test_http_status_mapping_survives_invalid_error_bodies(
        int $status,
        string $body,
        string $contentType,
        string $expectedClass,
        string $expectedMessage,
        string $expectedErrorCode,
    ): void {
        Http::fake(['*' => Http::response($body, $status, ['Content-Type' => $contentType])]);

        try {
            $this->app->make(Client::class)->payees()->list();
            self::fail('Expected a typed API exception.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expectedClass, $exception);
            self::assertSame($status, $exception->statusCode);
            self::assertSame($expectedMessage, $exception->getMessage());
            self::assertSame($expectedErrorCode, $exception->errorCode);
        }
    }

    public function test_transport_failures_discard_the_sensitive_original_exception(): void
    {
        Http::fake(static function (): never {
            throw new RuntimeException('Connection failed with Bearer '.self::API_KEY);
        });

        try {
            $this->app->make(Client::class)->payees()->list();
            self::fail('Expected a transport exception.');
        } catch (TransportException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(self::API_KEY, (string) $exception);
            self::assertStringNotContainsString(self::API_KEY, print_r($exception, true));
        }
    }

    public function test_invalid_response_trace_never_retains_the_response_body_or_credential(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        Http::fake([
            '*' => Http::response('{"credential":"'.self::API_KEY.'"', 200, ['Content-Type' => 'application/json']),
        ]);

        try {
            $this->app->make(Client::class)->payees()->list();
            self::fail('Expected an invalid response exception.');
        } catch (InvalidResponseException $exception) {
            $trace = print_r($this->sdkTrace($exception), true);

            self::assertStringNotContainsString(self::API_KEY, $trace);
            self::assertStringNotContainsString(self::API_KEY, print_r($exception, true));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /** @return iterable<string, array{int, class-string<ApiException>, string, string}> */
    public static function httpErrorCases(): iterable
    {
        yield '401' => [401, AuthenticationException::class, 'Allocora API authentication failed.', 'AUTHENTICATION_ERROR'];
        yield '403' => [403, AuthorizationException::class, 'The API key does not have permission to perform this Allocora operation.', 'AUTHORIZATION_ERROR'];
        yield '404' => [404, NotFoundException::class, 'The requested Allocora resource was not found.', 'NOT_FOUND'];
        yield '409' => [409, ApiException::class, 'The Allocora API returned an error.', 'API_ERROR'];
        yield '422' => [422, ValidationException::class, 'The Allocora API rejected the request data.', 'VALIDATION_ERROR'];
        yield '429' => [429, RateLimitException::class, 'The Allocora API rate limit was exceeded.', 'RATE_LIMIT_ERROR'];
        yield '500' => [500, ServerException::class, 'The Allocora API is temporarily unavailable.', 'SERVER_ERROR'];
        yield '503' => [503, ServerException::class, 'The Allocora API is temporarily unavailable.', 'SERVER_ERROR'];
    }

    /** @return iterable<string, array{int, string, string, string, string, list<string>}> */
    public static function sensitiveUpstreamErrorCases(): iterable
    {
        yield 'validation environment disclosure' => [
            422,
            'message',
            'Validation failed while reading /example/not-a-real-server-path/example.env: DB_PASSWORD=example-not-a-real-secret',
            'The Allocora API rejected the request data.',
            'VALIDATION_ERROR',
            ['/example/not-a-real-server-path/example.env', 'DB_PASSWORD', 'example-not-a-real-secret'],
        ];
        yield 'server SQL disclosure' => [
            500,
            'error',
            'SQLSTATE[HY000]: password=example-not-a-real-secret at /example/not-a-real-server-path/Revenue.php:91',
            'The Allocora API is temporarily unavailable.',
            'SERVER_ERROR',
            ['SQLSTATE[HY000]', 'example-not-a-real-secret', '/example/not-a-real-server-path/Revenue.php'],
        ];
        yield 'server Windows path disclosure' => [
            503,
            'message',
            'Unhandled exception at C:\\example\\not-a-real-server-path\\Service.php:44; APP_KEY=example-not-a-real-secret',
            'The Allocora API is temporarily unavailable.',
            'SERVER_ERROR',
            ['C:\\example\\not-a-real-server-path', 'APP_KEY', 'example-not-a-real-secret'],
        ];
        yield 'unknown status secret disclosure' => [
            418,
            'error',
            'AWS_SECRET_ACCESS_KEY=example-not-a-real-secret at /example/not-a-real-server-path/bootstrap.php:12',
            'The Allocora API returned an error.',
            'API_ERROR',
            ['AWS_SECRET_ACCESS_KEY', 'example-not-a-real-secret', '/example/not-a-real-server-path/bootstrap.php'],
        ];
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function invalidResponseCases(): iterable
    {
        yield 'missing content type' => ['{}', null];
        yield 'non-json content type' => ['{}', 'text/html'];
        yield 'malformed json' => ['{bad', 'application/json'];
        yield 'json list' => ['[]', 'application/json'];
        yield 'json scalar' => ['true', 'application/json'];
    }

    /** @return iterable<string, array{int, string, string, class-string<ApiException>, string, string}> */
    public static function invalidErrorResponseCases(): iterable
    {
        yield 'html 401' => [
            401,
            '<html>Unauthorized</html>',
            'text/html',
            AuthenticationException::class,
            'Allocora API authentication failed.',
            'AUTHENTICATION_ERROR',
        ];
        yield 'malformed 500' => [
            500,
            '{bad',
            'application/json',
            ServerException::class,
            'The Allocora API is temporarily unavailable.',
            'SERVER_ERROR',
        ];
    }

    /** @return iterable<string, array{int, class-string<ApiException>, string, string}> */
    public static function oversizedErrorResponseCases(): iterable
    {
        yield 'oversized 401' => [
            401,
            AuthenticationException::class,
            'Allocora API authentication failed.',
            'AUTHENTICATION_ERROR',
        ];
        yield 'oversized 503' => [
            503,
            ServerException::class,
            'The Allocora API is temporarily unavailable.',
            'SERVER_ERROR',
        ];
    }

    /** @return iterable<string, array{array<string, string|list<string>>}> */
    public static function unsafeSuccessfulResponseHeaderCases(): iterable
    {
        $tooManyFields = [];
        for ($index = 1; $index <= 101; $index++) {
            $tooManyFields['X-Field-'.$index] = 'value';
        }

        $tooManyLines = [
            'X-Many-Values' => array_fill(0, 201, 'value'),
        ];

        $tooManyAggregateBytes = [];
        for ($index = 1; $index <= 9; $index++) {
            $tooManyAggregateBytes['X-Large-'.$index] = str_repeat('v', 8_000);
        }

        yield 'field count' => [$tooManyFields];
        yield 'line count' => [$tooManyLines];
        yield 'name length' => [['X'.str_repeat('N', 128) => 'value']];
        yield 'value length' => [['X-Large-Value' => str_repeat('v', 8_193)]];
        yield 'aggregate bytes' => [$tooManyAggregateBytes];
    }

    private function clientWithHandler(MockHandler $handler, int $maxResponseBytes = 10_485_760): Client
    {
        return $this->clientWithFactory($this->factoryWithHandler($handler), $maxResponseBytes);
    }

    private function clientWithFactory(Factory $http, int $maxResponseBytes = 10_485_760): Client
    {
        return new Client(
            http: $http,
            apiKey: self::API_KEY,
            baseUrl: self::BASE_URL,
            maxResponseBytes: $maxResponseBytes,
        );
    }

    private function factoryWithHandler(MockHandler $handler): Factory
    {
        return new class($handler) extends Factory
        {
            public function __construct(private readonly MockHandler $handler)
            {
                parent::__construct();
            }

            public function createPendingRequest(): PendingRequest
            {
                return parent::createPendingRequest()->setHandler($this->handler);
            }
        };
    }

    /** @return list<array<string, mixed>> */
    private function sdkTrace(Throwable $exception): array
    {
        return array_values(array_filter(
            $exception->getTrace(),
            static fn (array $frame): bool => is_string($frame['class'] ?? null)
                && str_starts_with($frame['class'], 'Elvesora\\Allocora\\Laravel\\'),
        ));
    }
}
