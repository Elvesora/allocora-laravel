# Security Policy

## Supported versions

Security updates are provided for the latest `0.1.x` release until a newer supported release line is announced.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub private vulnerability reporting for the `Elvesora/allocora-laravel` repository. Include the affected version, impact, reproduction steps, and any proposed remediation.

Never include real Allocora API keys, customer data, financial records, or private URLs in a report. Use clearly fictional values and revoke any credential that may have been exposed.

We will acknowledge a complete report, investigate it, and coordinate remediation and disclosure through the private report. Public disclosure should wait until a fix is available.

## Consumer responsibilities

The package verifies TLS, rejects redirects, disables transparent response decompression, isolates Allocora requests from shared Laravel HTTP-factory global options and middleware, bounds request bodies, response bodies, query strings, successful response headers, and selected error metadata headers before parsing, protects configured HTTP factories and container configuration in exception traces and exports, sanitizes exceptions and response debug/export surfaces, blocks client and response serialization, and does not retry automatically. Laravel HTTP fakes and request recording remain available for application tests. Applications must still keep API keys, private URLs, customer data, and financial data out of source control, logs, queues, caches, telemetry, browser output, and user-visible errors.
