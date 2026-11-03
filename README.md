# Allocora Laravel

`elvesora/allocora-laravel` is the self-contained Laravel client for Allocora's public v1 API. It supports Laravel 12 and 13 and provides automatic discovery, dependency injection, a facade, publishable configuration, typed exceptions, and explicit resource methods.

## Requirements

| Laravel | Supported PHP versions |
| --- | --- |
| 12 | 8.2, 8.3, 8.4, or 8.5 |
| 13 | 8.3, 8.4, or 8.5 |

The JSON and Mbstring PHP extensions are required. You also need an Allocora API key with the scopes required by your operations.

## Installation

```bash
composer require elvesora/allocora-laravel:^0.1
php artisan vendor:publish --tag=allocora-config
```

Composer resolves stable releases through Packagist. Pin the compatible `^0.1` series as shown above and review the changelog before upgrading.

Add the key to your deployment environment. Never commit it:

```dotenv
ALLOCORA_API_KEY=replace_with_your_allocora_api_key
ALLOCORA_BASE_URL=https://www.allocora.com/api/v1
ALLOCORA_TIMEOUT=30
ALLOCORA_CONNECT_TIMEOUT=5
ALLOCORA_MAX_RESPONSE_BYTES=10485760
```

Laravel package discovery registers `AllocoraServiceProvider`. The published `config/allocora.php` is the only place that reads these environment values.

## Quick start

Inject the contract into a controller, job, listener, or service:

```php
<?php

use Elvesora\Allocora\Laravel\Contracts\ClientInterface;

final class SynchronizePayees
{
    public function __construct(private readonly ClientInterface $allocora) {}

    public function __invoke(): array
    {
        return (array) $this->allocora->payees()->list()->data();
    }
}
```

The concrete client resolves from the container as well:

```php
use Elvesora\Allocora\Laravel\Client;

$allocora = app(Client::class);
$response = $allocora->products()->show($productId);
```

The optional facade provides the same resource methods:

```php
use Elvesora\Allocora\Laravel\Facades\Allocora;

$payees = Allocora::payees()->list()->data();
```

Write methods accept associative arrays that match the public API payload. For example, revenue records are append-only:

```php
$response = $allocora->revenues()->create([
    'revenue_source_id' => $revenueSourceId,
    'external_id' => 'order-1001',
    'type' => 'sale',
    'amount' => '150.000',
    'currency' => 'USD',
    'occurred_at' => '2026-08-11T10:00:00Z',
    'product_id' => $productId,
]);

$revenue = $response->data();
```

The [public API reference](https://www.allocora.com/docs/api-endpoints) documents the complete request and response shapes for every resource.

## Authentication and scopes

Every request uses `Authorization: Bearer <key>`. Create keys with only the scopes your integration needs:

| Resource | Read scope | Write scope |
| --- | --- | --- |
| Payees | `read:payees` | `write:payees` |
| Products | `read:products` | `write:products` |
| Revenues | `read:revenues` | `write:revenues` |
| Rules | `read:rules` | `write:rules` |

Invalid keys raise `AuthenticationException`; insufficient scope raises `AuthorizationException`.

## Resource methods

All methods return `Elvesora\Allocora\Laravel\ApiResponse`.

```php
// Payees
$allocora->payees()->list($query);
$allocora->payees()->show($id);
$allocora->payees()->create($record);
$allocora->payees()->batchCreate($records);
$allocora->payees()->upsert($record);
$allocora->payees()->batchUpsert($records);
$allocora->payees()->update($id, $changes);
$allocora->payees()->batchUpdate($recordsWithIds);

// Products: the same method set, plus archival
$allocora->products()->archive($id);
$allocora->products()->batchArchive($recordsWithIds);

// Immutable revenue records
$allocora->revenues()->list($query);
$allocora->revenues()->show($id);
$allocora->revenues()->create($record);
$allocora->revenues()->batchCreate($records);

// Rules: the payee method set, plus status and version history
$allocora->rules()->toggleStatus($id);
$allocora->rules()->versions($id);
```

### Retail-price (RRP) rule allocations

A flat allocation can calculate its royalty on the retail price instead of actual receipts. Rule reads (`show`, `list`, `versions`) return the stored allocation keys unchanged:

```php
foreach ($allocora->rules()->show($id)->data()['allocations'] as $allocation) {
    $basis = $allocation['royalty_basis'] ?? 'receipts'; // a missing key means actual receipts
    $source = $allocation['rrp_source'] ?? null;          // 'file' or 'product_history' when $basis is 'rrp'
    $rate = $allocation['percentage'];                    // for 'rrp': percent of units × retail price, not of receipts
}
```

Treat any other `royalty_basis` value as unknown rather than as receipts. Retail-price allocations are created and changed in the Allocora workspace rule editor. Through the API you can rename a rule, change its status, or toggle it; send only the fields you change, for example `$allocora->rules()->update($id, ['name' => 'Retail royalty 2027'])`.

The API returns `422` (`ValidationException`) and creates no rule version when a write sends `royalty_basis` or `rrp_source` in an allocation, or sends `allocations` or `allocation_type` for a rule that uses the retail-price basis — including the allocations you just read. Do not retry such a request; change the allocation in the workspace instead.

Revenue records have no update or delete methods. Correct an error through Allocora's documented refund or adjustment model. Product archival changes status rather than deleting financial history.

Revenue listing alone is paginated. It accepts `page`, `per_page` (default 25, maximum 100), `type`, `revenue_source_id`, `currency`, `date_from`, `date_to`, and `search`; pagination details are returned in `meta()` as `current_page`, `last_page`, `per_page`, and `total`.

Batch methods accept 1 to 100 keyed records and send the required `{"records":[...]}` envelope. Update and archive records require an `id` UUID. A successful batch response can contain both successful and failed records, so inspect `data()` and the `processed`, `succeeded`, and `failed` values in `meta()`.

## Responses

`ApiResponse` is immutable:

```php
$response->status();
$response->body();
$response->headers();
$response->header('X-Request-Id');
$response->headerLine('Retry-After');
$response->data();
$response->meta();
```

Successful responses must be JSON objects with a JSON content type. Malformed, non-object, non-JSON, compressed, oversized, or abnormally header-heavy successful responses raise `InvalidResponseException`. Response bodies and header values are available only through the explicit accessors above; debug and export output expose metadata rather than their contents, and native serialization is blocked. HTTP error responses remain status-typed even when their bodies are malformed, compressed, or oversized.

## Errors and retries

The package maps 401, 403, 404, 422, 429, and 5xx responses to `AuthenticationException`, `AuthorizationException`, `NotFoundException`, `ValidationException`, `RateLimitException`, and `ServerException`. Other non-2xx responses raise `ApiException`; network failures raise `TransportException`.

Client-side failures use `ConfigurationException` for invalid or missing package configuration and `InvalidInputException` for invalid identifiers, query parameters, payloads, or batch input. These failures are raised before an HTTP request is sent; correct the input or configuration instead of retrying it unchanged.

`ApiException` exposes `statusCode`, a fixed status-category `errorCode`, `retryAfterSeconds`, and a sanitized `requestId`. Error response bodies are untrusted and are never copied into exception messages, codes, traces, or debug output. The fixed codes are `AUTHENTICATION_ERROR`, `AUTHORIZATION_ERROR`, `NOT_FOUND`, `VALIDATION_ERROR`, `RATE_LIMIT_ERROR`, `SERVER_ERROR`, and `API_ERROR`.

The client never retries automatically. Writes can represent financial facts and are not assumed idempotent. Reconcile the result before retrying a create, update, upsert, batch, archive, or rule-status operation, use stable external identifiers when supported, and respect `retryAfterSeconds` for 429 responses.

## Transport and credential safety

- HTTPS base URL ending in `/api/v1`; credentials, query strings, fragments, whitespace, and trailing slash are rejected.
- 30-second request and 5-second connection timeouts by default.
- TLS certificate verification enabled and redirects disabled.
- Requests explicitly require identity encoding and transparent HTTP decompression is disabled.
- Shared Laravel HTTP-factory global options and middleware are not inherited, preventing unrelated headers, credentials, cookies, query parameters, bodies, or transport settings from entering Allocora requests. Factory fakes and request recording remain available for application tests.
- Request bodies limited to 2 MB, query strings to 8 KB, and response bodies to 10 MiB by default.
- Successful response headers are limited to 100 fields, 200 values, 128-byte names, 8 KiB values, and 64 KiB in aggregate.
- Non-success `Retry-After`, `X-Request-ID`, and `X-Correlation-ID` metadata is length- and count-bounded before parsing; rejected metadata is exposed as `null` without changing the typed status error.
- API-key, private-base-URL, HTTP-factory, request-data, and container-configuration trace parameters are marked with PHP `SensitiveParameter`.
- Client and response serialization is blocked; their debug and export surfaces do not expose credentials, response bodies, or response header values.
- Fixed transport and status-category API errors do not echo arbitrary upstream response details.
- No automatic retries and no generic request method on the public client.

Applications remain responsible for keeping keys out of source control, logs, queues, caches, telemetry, browser output, and user-visible errors. Rotate a disclosed key immediately.

The package surface is locked to the 32 operations in `resources/public-api-manifest.json` across payees, products, revenue records, and rules.

## Development

```bash
composer install
composer format
composer check
composer archive --format=zip
```

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
