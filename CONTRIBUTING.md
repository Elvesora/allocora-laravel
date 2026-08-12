# Contributing

Thank you for improving the Allocora Laravel package.

## Development setup

Requirements are PHP 8.2 through 8.5, Composer 2, JSON, and Mbstring.

```bash
composer install
composer check
```

Run `composer format` before submitting a change. The complete `composer check` gate validates package metadata, formatting, tests, static analysis, and dependency security advisories.

## Change requirements

- Keep the package independently installable; do not add a dependency on a sibling checkout or the Allocora application.
- Keep `resources/public-api-manifest.json`, resource methods, tests, README, and changelog synchronized.
- Add tests for success, failure, malformed input, malformed response, and secret-redaction behavior affected by the change.
- Use fictional credentials and data only. Do not commit local paths, environment files, customer data, logs, dumps, or generated archives.
- Preserve the no-automatic-retry policy for financial writes unless the public API introduces and documents a safe idempotency contract.

## Pull requests

Explain the user-visible behavior, compatibility impact, and verification performed. Security reports must follow [SECURITY.md](SECURITY.md) instead of a public issue.
