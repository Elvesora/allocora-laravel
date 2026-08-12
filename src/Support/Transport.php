<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Support;

use Elvesora\Allocora\Laravel\ApiResponse;
use Elvesora\Allocora\Laravel\Exceptions\ApiException;
use Elvesora\Allocora\Laravel\Exceptions\AuthenticationException;
use Elvesora\Allocora\Laravel\Exceptions\AuthorizationException;
use Elvesora\Allocora\Laravel\Exceptions\ConfigurationException;
use Elvesora\Allocora\Laravel\Exceptions\InvalidInputException;
use Elvesora\Allocora\Laravel\Exceptions\InvalidResponseException;
use Elvesora\Allocora\Laravel\Exceptions\NotFoundException;
use Elvesora\Allocora\Laravel\Exceptions\RateLimitException;
use Elvesora\Allocora\Laravel\Exceptions\ServerException;
use Elvesora\Allocora\Laravel\Exceptions\TransportException;
use Elvesora\Allocora\Laravel\Exceptions\ValidationException;
use Elvesora\Allocora\Laravel\Internal\Operation;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use SensitiveParameterValue;
use stdClass;
use Throwable;

/** @internal */
final class Transport
{
    private const MAX_REQUEST_BYTES = 2_000_000;

    private const MAX_QUERY_BYTES = 8_192;

    private const MAX_RESPONSE_HEADER_FIELDS = 100;

    private const MAX_RESPONSE_HEADER_LINES = 200;

    private const MAX_RESPONSE_HEADER_NAME_BYTES = 128;

    private const MAX_RESPONSE_HEADER_VALUE_BYTES = 8_192;

    private const MAX_RESPONSE_HEADER_BYTES = 65_536;

    private const MAX_ERROR_METADATA_HEADER_VALUES = 10;

    private const MAX_RETRY_AFTER_HEADER_BYTES = 128;

    private const MAX_REQUEST_ID_HEADER_BYTES = 200;

    private const USER_AGENT = 'elvesora-allocora-laravel/0.1.0';

    private readonly SensitiveParameterValue $http;

    private readonly SensitiveParameterValue $apiKey;

    private readonly SensitiveParameterValue $baseUrl;

    public function __construct(
        #[\SensitiveParameter]
        Factory $http,
        #[\SensitiveParameter]
        string $apiKey,
        #[\SensitiveParameter]
        string $baseUrl,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        private readonly int $maxResponseBytes,
    ) {
        $this->http = new SensitiveParameterValue($http);
        $this->apiKey = new SensitiveParameterValue($apiKey);
        $this->assertConfiguration($baseUrl);
        $this->baseUrl = new SensitiveParameterValue(rtrim($baseUrl, '/'));
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<array-key, mixed>|null  $payload
     */
    public function request(
        Operation $operation,
        #[\SensitiveParameter]
        ?string $resourceId = null,
        #[\SensitiveParameter]
        array $query = [],
        #[\SensitiveParameter]
        ?array $payload = null,
    ): ApiResponse {
        $method = $operation->method();
        $path = $operation->path();

        if ($operation->requiresIdentifier()) {
            if ($resourceId === null) {
                throw new InvalidInputException('This Allocora operation requires a resource UUID.');
            }

            $path = str_replace('{id}', $this->identifier($resourceId), $path);
        } elseif ($resourceId !== null) {
            throw new InvalidInputException('This Allocora operation does not accept a resource UUID.');
        }

        $this->assertQuery($query);

        $json = $payload === null ? null : $this->encodePayload($payload);
        $responseTooLarge = false;
        $responseEncodingRejected = false;
        $responseHeadersRejected = false;
        $receivedStatus = null;
        $receivedRetryAfter = null;
        $receivedRequestId = null;
        $headers = $this->requestHeaders();

        $pendingRequest = $this->createPendingRequest()
            ->baseUrl($this->baseUrl())
            ->withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions([
                'allow_redirects' => false,
                'decode_content' => false,
                'verify' => true,
                'on_headers' => function (#[\SensitiveParameter] ResponseInterface $response) use (
                    &$responseTooLarge,
                    &$responseEncodingRejected,
                    &$responseHeadersRejected,
                    &$receivedStatus,
                    &$receivedRetryAfter,
                    &$receivedRequestId,
                ): void {
                    $receivedStatus = $response->getStatusCode();
                    $receivedRetryAfter = $this->boundedHeaderLine(
                        $response,
                        'Retry-After',
                        self::MAX_RETRY_AFTER_HEADER_BYTES,
                    );
                    $receivedRequestId = $this->boundedHeaderLine(
                        $response,
                        'X-Request-ID',
                        self::MAX_REQUEST_ID_HEADER_BYTES,
                    );

                    if ($receivedRequestId === null || $receivedRequestId === '') {
                        $receivedRequestId = $this->boundedHeaderLine(
                            $response,
                            'X-Correlation-ID',
                            self::MAX_REQUEST_ID_HEADER_BYTES,
                        );
                    }

                    if ($receivedStatus >= 200 && $receivedStatus < 300) {
                        if (! $this->responseHeadersAreWithinLimits($response)) {
                            $responseHeadersRejected = true;

                            throw new InvalidResponseException('The Allocora response headers exceeded the safety limits.');
                        }

                        if (! $this->usesIdentityEncoding($response->getHeaderLine('Content-Encoding'))) {
                            $responseEncodingRejected = true;

                            throw new InvalidResponseException('Allocora returned an unsupported content encoding.');
                        }
                    }

                    $contentLength = $response->getHeaderLine('Content-Length');

                    if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $this->maxResponseBytes) {
                        $responseTooLarge = true;

                        throw new InvalidResponseException('The Allocora response exceeded the configured size limit.');
                    }
                },
                'progress' => function (int $downloadTotal, int $downloadedBytes) use (&$responseTooLarge): void {
                    if ($downloadTotal > $this->maxResponseBytes || $downloadedBytes > $this->maxResponseBytes) {
                        $responseTooLarge = true;

                        throw new InvalidResponseException('The Allocora response exceeded the configured size limit.');
                    }
                },
            ]);

        if ($json !== null) {
            $pendingRequest = $pendingRequest->withBody($json, 'application/json');
        }

        try {
            $response = $pendingRequest->send($method, $path, $query === [] ? [] : ['query' => $query]);
        } catch (Throwable) {
            if (
                $receivedStatus !== null
                && ($receivedStatus < 200 || $receivedStatus >= 300)
                && $responseTooLarge
            ) {
                $this->throwApiExceptionFromMetadata(
                    status: $receivedStatus,
                    retryAfterHeader: $receivedRetryAfter,
                    requestIdHeader: $receivedRequestId,
                );
            }

            if ($responseEncodingRejected) {
                throw new InvalidResponseException('Allocora returned an unsupported content encoding.');
            }

            if ($responseHeadersRejected) {
                throw new InvalidResponseException('The Allocora response headers exceeded the safety limits.');
            }

            if ($responseTooLarge) {
                throw new InvalidResponseException('The Allocora response exceeded the configured size limit.');
            }

            throw new TransportException('The request to Allocora could not be completed.');
        }

        return $this->response($response);
    }

    public function identifier(#[\SensitiveParameter] string $identifier): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $identifier) !== 1) {
            throw new InvalidInputException('Allocora resource IDs must be canonical UUID strings.');
        }

        return rawurlencode($identifier);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'api_key' => '[REDACTED]',
            'base_url' => '[REDACTED]',
            'timeout' => (string) $this->timeout,
            'connect_timeout' => (string) $this->connectTimeout,
            'max_response_bytes' => (string) $this->maxResponseBytes,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('The Allocora client cannot be serialized.');
    }

    private function assertConfiguration(#[\SensitiveParameter] string $baseUrl): void
    {
        if (preg_match('/\A[\x21-\x7E]{8,4096}\z/D', $this->apiKey()) !== 1) {
            throw new ConfigurationException('The Allocora API key must be 8 to 4096 printable ASCII characters without whitespace.');
        }

        if ($baseUrl !== trim($baseUrl) || str_ends_with($baseUrl, '/')) {
            throw new ConfigurationException('The Allocora base URL cannot contain surrounding whitespace or a trailing slash.');
        }

        $parts = parse_url($baseUrl);

        if (
            filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! str_ends_with(rtrim((string) ($parts['path'] ?? ''), '/'), '/api/v1')
        ) {
            throw new ConfigurationException('The Allocora base URL must be an HTTPS URL ending in /api/v1 without credentials, a query, or a fragment.');
        }

        if (! is_finite($this->timeout) || $this->timeout <= 0 || $this->timeout > 300) {
            throw new ConfigurationException('The Allocora request timeout must be greater than zero and no more than 300 seconds.');
        }

        if (! is_finite($this->connectTimeout) || $this->connectTimeout <= 0 || $this->connectTimeout > $this->timeout) {
            throw new ConfigurationException('The Allocora connect timeout must be greater than zero and no greater than the request timeout.');
        }

        if ($this->maxResponseBytes < 1_024 || $this->maxResponseBytes > 50_000_000) {
            throw new ConfigurationException('The Allocora response size limit must be between 1,024 and 50,000,000 bytes.');
        }
    }

    /** @param array<array-key, mixed> $query */
    private function assertQuery(#[\SensitiveParameter] array $query): void
    {
        if ($query !== [] && array_is_list($query)) {
            throw new InvalidInputException('Query parameters must be a keyed object.');
        }

        foreach ($query as $name => $value) {
            if (! is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/D', $name) !== 1) {
                throw new InvalidInputException('Query parameter names must start with a letter and contain only letters, numbers, dots, underscores, or hyphens.');
            }

            if (is_array($value)) {
                if (! array_is_list($value)) {
                    throw new InvalidInputException(sprintf('The query parameter "%s" must be a scalar or a list of scalars.', $name));
                }

                foreach ($value as $item) {
                    if ($item !== null && ! is_scalar($item)) {
                        throw new InvalidInputException(sprintf('The query parameter "%s" contains an unsupported value.', $name));
                    }
                }
            } elseif ($value !== null && ! is_scalar($value)) {
                throw new InvalidInputException(sprintf('The query parameter "%s" must be a scalar or a list of scalars.', $name));
            }
        }

        if (strlen(http_build_query($query, '', '&', PHP_QUERY_RFC3986)) > self::MAX_QUERY_BYTES) {
            throw new InvalidInputException('Query parameters exceeded the size limit.');
        }
    }

    /** @param array<array-key, mixed> $payload */
    private function encodePayload(#[\SensitiveParameter] array $payload): string
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidInputException('Request payloads must be keyed JSON objects.');
        }

        try {
            $json = json_encode(
                $payload === [] ? new stdClass : $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw new InvalidInputException('The request payload must contain valid JSON-compatible values.');
        }

        if (strlen($json) > self::MAX_REQUEST_BYTES) {
            throw new InvalidInputException('The request payload exceeded the size limit.');
        }

        return $json;
    }

    /** @return array<string, string> */
    private function requestHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Accept-Encoding' => 'identity',
            'Authorization' => 'Bearer '.$this->apiKey(),
            'User-Agent' => self::USER_AGENT,
        ];

        foreach ($headers as $name => $value) {
            if (preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/D', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                throw new ConfigurationException('An Allocora request header was invalid.');
            }
        }

        return $headers;
    }

    private function response(#[\SensitiveParameter] Response $response): ApiResponse
    {
        if (! $response->successful()) {
            $this->throwApiException($response);
        }

        if (! $this->responseHeadersAreWithinLimits($response->toPsrResponse())) {
            throw new InvalidResponseException('The Allocora response headers exceeded the safety limits.');
        }

        if (! $this->usesIdentityEncoding($response->header('Content-Encoding'))) {
            throw new InvalidResponseException('Allocora returned an unsupported content encoding.');
        }

        $body = $response->body();

        if (strlen($body) > $this->maxResponseBytes) {
            throw new InvalidResponseException('The Allocora response exceeded the configured size limit.');
        }

        $decoded = $this->decodeObject($body, $response->header('Content-Type'));

        return new ApiResponse($response->status(), $decoded, $this->normalizeHeaders($response->headers()));
    }

    /** @return array<string, mixed> */
    private function decodeObject(#[\SensitiveParameter] string $body, ?string $contentType): array
    {
        if ($contentType === null || preg_match('#\Aapplication/(?:[A-Za-z0-9.+-]+\+)?json(?:\s*;|\z)#i', trim($contentType)) !== 1) {
            throw new InvalidResponseException('Allocora returned a non-JSON response.');
        }

        try {
            $object = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidResponseException('Allocora returned malformed JSON.');
        }

        if (! $object instanceof stdClass || ! is_array($decoded)) {
            throw new InvalidResponseException('Allocora responses must be JSON objects.');
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidResponseException('Allocora response objects must use string keys.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function throwApiException(#[\SensitiveParameter] Response $response): never
    {
        $psrResponse = $response->toPsrResponse();
        $requestId = $this->boundedHeaderLine(
            $psrResponse,
            'X-Request-ID',
            self::MAX_REQUEST_ID_HEADER_BYTES,
        );

        if ($requestId === null || $requestId === '') {
            $requestId = $this->boundedHeaderLine(
                $psrResponse,
                'X-Correlation-ID',
                self::MAX_REQUEST_ID_HEADER_BYTES,
            );
        }

        $this->throwApiExceptionFromMetadata(
            status: $response->status(),
            retryAfterHeader: $this->boundedHeaderLine(
                $psrResponse,
                'Retry-After',
                self::MAX_RETRY_AFTER_HEADER_BYTES,
            ),
            requestIdHeader: $requestId,
        );
    }

    private function throwApiExceptionFromMetadata(
        int $status,
        #[\SensitiveParameter]
        ?string $retryAfterHeader,
        #[\SensitiveParameter]
        ?string $requestIdHeader,
    ): never {
        $message = $this->errorMessage($status);
        $errorCode = $this->errorCode($status);
        $retryAfter = $this->retryAfter($retryAfterHeader);
        $requestId = $this->safeIdentifier($requestIdHeader);
        $exceptionClass = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => AuthorizationException::class,
            $status === 404 => NotFoundException::class,
            $status === 422 => ValidationException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => ApiException::class,
        };

        throw new $exceptionClass($message, $status, $errorCode, $retryAfter, $requestId);
    }

    private function errorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Allocora API authentication failed.',
            403 => 'The API key does not have permission to perform this Allocora operation.',
            404 => 'The requested Allocora resource was not found.',
            422 => 'The Allocora API rejected the request data.',
            429 => 'The Allocora API rate limit was exceeded.',
            default => $status >= 500
                ? 'The Allocora API is temporarily unavailable.'
                : 'The Allocora API returned an error.',
        };
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            401 => 'AUTHENTICATION_ERROR',
            403 => 'AUTHORIZATION_ERROR',
            404 => 'NOT_FOUND',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMIT_ERROR',
            default => $status >= 500 ? 'SERVER_ERROR' : 'API_ERROR',
        };
    }

    private function retryAfter(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strlen($value) > self::MAX_RETRY_AFTER_HEADER_BYTES) {
            return null;
        }

        if (ctype_digit($value)) {
            return min((int) $value, 604_800);
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : min(max(0, $timestamp - time()), 604_800);
    }

    private function safeIdentifier(?string $value): ?string
    {
        if ($value === null || strlen($value) > self::MAX_REQUEST_ID_HEADER_BYTES) {
            return null;
        }

        return preg_match('/\A[A-Za-z0-9._:-]{1,200}\z/D', $value) === 1 ? $value : null;
    }

    private function apiKey(): string
    {
        $value = $this->apiKey->getValue();

        if (! is_string($value)) {
            throw new LogicException('The protected Allocora credential is invalid.');
        }

        return $value;
    }

    private function http(): Factory
    {
        $value = $this->http->getValue();

        if (! $value instanceof Factory) {
            throw new LogicException('The protected Allocora HTTP factory is invalid.');
        }

        return $value;
    }

    private function createPendingRequest(): PendingRequest
    {
        $factory = $this->http();
        $pendingRequest = $this->createPendingRequestWithNativeIsolation($factory);

        if ($pendingRequest !== null) {
            return $pendingRequest;
        }

        return $this->createPendingRequestWithoutGlobalConfiguration($factory);
    }

    private function createPendingRequestWithNativeIsolation(
        #[\SensitiveParameter]
        object $factory,
    ): ?PendingRequest {
        if (! method_exists($factory, 'withoutGlobalConfiguration')) {
            return null;
        }

        if (! $factory instanceof Factory) {
            throw new LogicException('The protected Allocora HTTP factory is invalid.');
        }

        $pendingRequest = $factory->withoutGlobalConfiguration(
            static fn (): PendingRequest => $factory->createPendingRequest(),
        );

        return $this->validatedPendingRequest($pendingRequest);
    }

    private function validatedPendingRequest(#[\SensitiveParameter] mixed $pendingRequest): PendingRequest
    {
        if (! $pendingRequest instanceof PendingRequest) {
            throw new LogicException('The Allocora HTTP factory returned an invalid pending request.');
        }

        return $pendingRequest;
    }

    private function createPendingRequestWithoutGlobalConfiguration(
        #[\SensitiveParameter]
        Factory $factory,
    ): PendingRequest {
        $globalMiddlewareProperty = new ReflectionProperty(Factory::class, 'globalMiddleware');
        $globalOptionsProperty = new ReflectionProperty(Factory::class, 'globalOptions');
        $globalMiddleware = $globalMiddlewareProperty->getValue($factory);
        $globalOptions = $globalOptionsProperty->getValue($factory);

        try {
            $globalMiddlewareProperty->setValue($factory, []);
            $globalOptionsProperty->setValue($factory, []);
            $pendingRequest = $factory->createPendingRequest();
        } finally {
            $globalMiddlewareProperty->setValue($factory, $globalMiddleware);
            $globalOptionsProperty->setValue($factory, $globalOptions);
        }

        return $pendingRequest;
    }

    private function baseUrl(): string
    {
        $value = $this->baseUrl->getValue();

        if (! is_string($value)) {
            throw new LogicException('The protected Allocora base URL is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(#[\SensitiveParameter] array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            if (! is_string($name) || ! is_array($values)) {
                continue;
            }

            $normalizedValues = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $normalizedValues[] = $value;
                }
            }

            $normalized[$name] = $normalizedValues;
        }

        return $normalized;
    }

    private function usesIdentityEncoding(#[\SensitiveParameter] ?string $contentEncoding): bool
    {
        if ($contentEncoding === null || trim($contentEncoding) === '') {
            return true;
        }

        return strcasecmp(trim($contentEncoding), 'identity') === 0;
    }

    private function responseHeadersAreWithinLimits(#[\SensitiveParameter] ResponseInterface $response): bool
    {
        $headers = $response->getHeaders();

        if (count($headers) > self::MAX_RESPONSE_HEADER_FIELDS) {
            return false;
        }

        $headerLines = 0;
        $headerBytes = 0;

        foreach ($headers as $name => $values) {
            $nameBytes = strlen($name);

            if ($nameBytes === 0 || $nameBytes > self::MAX_RESPONSE_HEADER_NAME_BYTES) {
                return false;
            }

            foreach ($values as $value) {
                $headerLines++;
                $valueBytes = strlen($value);

                if ($headerLines > self::MAX_RESPONSE_HEADER_LINES || $valueBytes > self::MAX_RESPONSE_HEADER_VALUE_BYTES) {
                    return false;
                }

                $headerBytes += $nameBytes + $valueBytes + 4;

                if ($headerBytes > self::MAX_RESPONSE_HEADER_BYTES) {
                    return false;
                }
            }
        }

        return true;
    }

    private function boundedHeaderLine(
        #[\SensitiveParameter]
        ResponseInterface $response,
        string $name,
        int $maxBytes,
    ): ?string {
        $values = $response->getHeader($name);

        if ($values === []) {
            return null;
        }

        if (count($values) > self::MAX_ERROR_METADATA_HEADER_VALUES) {
            return null;
        }

        $bytes = 0;
        foreach ($values as $index => $value) {
            $bytes += strlen($value);

            if ($index > 0) {
                $bytes += 2;
            }

            if ($bytes > $maxBytes) {
                return null;
            }
        }

        return implode(', ', $values);
    }
}
