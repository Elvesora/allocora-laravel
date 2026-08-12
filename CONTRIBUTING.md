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

## Releases

The signed-tag policy applies to every release after `v0.1.0`. The immutable, attested `v0.1.0` release remains unchanged because its tag cannot be replaced.

Stable release tags must use format `vX.Y.Z`, be annotated, and be signed by a signing key registered with GitHub for a verified tagger identity. From a clean, reviewed `main` commit, create the tag locally and push its signed object only to the candidate namespace:

```bash
git tag -s vX.Y.Z -m "vX.Y.Z"
git tag -v vX.Y.Z
git push origin refs/tags/vX.Y.Z:refs/tags/release-candidates/vX.Y.Z
gh workflow run promote-release-tag.yml --ref main -f version=X.Y.Z
```

Do not push a public `vX.Y.Z` tag directly. The protected promotion environment holds the job for explicit approval. After approval, the workflow runs from the default branch, verifies the signed candidate against its exact commit, dispatches and waits for the complete `tests` workflow, and uses its repository-scoped GitHub Actions token to create the public tag only after every gate passes. Active repository rules prevent every actor from updating or deleting published public release tags.

Do not create or publish the GitHub release, or declare the Composer version supported, until `promote release tag / signed-tag` succeeds. Attach the audited distribution archive to a draft release before publication. Published release tags must never be moved, replaced, or deleted.
