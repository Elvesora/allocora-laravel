<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel;

use Elvesora\Allocora\Laravel\Exceptions\InvalidResponseException;
use LogicException;
use SensitiveParameterValue;

final readonly class ApiResponse
{
    private SensitiveParameterValue $body;

    private SensitiveParameterValue $headers;

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        private int $status,
        #[\SensitiveParameter]
        array $body,
        #[\SensitiveParameter]
        array $headers,
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidResponseException('The HTTP status must be between 100 and 599.');
        }

        $this->body = new SensitiveParameterValue($body);
        $this->headers = new SensitiveParameterValue($headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->protectedBody();
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->protectedHeaders();
    }

    /** @return list<string> */
    public function header(string $name): array
    {
        foreach ($this->protectedHeaders() as $headerName => $values) {
            if (strcasecmp($headerName, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    public function headerLine(string $name): string
    {
        return implode(', ', $this->header($name));
    }

    public function data(): mixed
    {
        return $this->protectedBody()['data'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function meta(): ?array
    {
        $meta = $this->protectedBody()['meta'] ?? null;

        if (! is_array($meta)) {
            return null;
        }

        $normalized = [];
        foreach ($meta as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @return array<string, int> */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'body_field_count' => count($this->protectedBody()),
            'header_field_count' => count($this->protectedHeaders()),
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Allocora API responses cannot be serialized.');
    }

    /** @return array<string, mixed> */
    private function protectedBody(): array
    {
        $body = $this->body->getValue();

        if (! is_array($body)) {
            throw new LogicException('The protected Allocora response body is invalid.');
        }

        $normalized = [];
        foreach ($body as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException('The protected Allocora response body is invalid.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @return array<string, list<string>> */
    private function protectedHeaders(): array
    {
        $headers = $this->headers->getValue();

        if (! is_array($headers)) {
            throw new LogicException('The protected Allocora response headers are invalid.');
        }

        $normalized = [];
        foreach ($headers as $name => $values) {
            if (! is_string($name) || ! is_array($values) || ! array_is_list($values)) {
                throw new LogicException('The protected Allocora response headers are invalid.');
            }

            $normalizedValues = [];
            foreach ($values as $value) {
                if (! is_string($value)) {
                    throw new LogicException('The protected Allocora response headers are invalid.');
                }

                $normalizedValues[] = $value;
            }

            $normalized[$name] = $normalizedValues;
        }

        return $normalized;
    }
}
