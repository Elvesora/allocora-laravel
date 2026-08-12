# Changelog

All notable changes to this package are documented here.

## [0.1.0] - 2026-08-12

### Added

- Standalone Laravel client for the 32 public Allocora API v1 operations.
- Automatic package discovery, dependency-injection binding, facade, and publishable configuration.
- Explicit payee, product, revenue, and rule resource clients.
- Immutable API responses and typed, sanitized exceptions.
- Fixed status-category exception messages and codes that never expose untrusted upstream error text.
- HTTPS boundary validation, explicit timeouts, TLS verification, disabled redirects, bounded payloads, and no automatic retries.
- Identity-only response encoding, disabled transparent decompression, bounded successful response-header retention, and pre-parse bounds for selected error metadata headers.
- Isolation from shared Laravel HTTP-factory global options and middleware while preserving factory fakes and request recording.
- Protected HTTP-factory and container-configuration traces, plus response debug, export, and serialization surfaces.
- Canonical public API contract manifest, Laravel 12/13 compatibility tests, static analysis, formatting, security audit, and archive verification.
