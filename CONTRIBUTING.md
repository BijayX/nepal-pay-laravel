# Contributing

Thanks for helping improve this package.

## Ground rules

- This package implements a contract shared with the Node.js package `@bijayx/nepal-pay`.
  Changes to statuses, error codes, amount handling or verification rules must be made in the
  shared specification first and applied to both packages.
- Amounts are integer paisa everywhere in the public API.
- Never log or include secret keys in exceptions, test output or fixtures for real merchants.
- Tests must not call real gateways. Use `Http::fake()` and the JSON fixtures in
  `tests/Fixtures`.

## Setup

```bash
git clone <your fork>
cd nepal-pay-laravel
composer install
```

## Before opening a pull request

```bash
composer lint       # vendor/bin/pint --test   (run `composer format` to fix)
composer analyse    # vendor/bin/phpstan analyse
composer test       # vendor/bin/pest
```

All three must pass. Add tests for every behaviour change and an entry under
`[Unreleased]` in `CHANGELOG.md`.

## Commit messages and pull requests

- Keep pull requests focused on one change.
- Describe the problem, the change, and how it was tested.
- Link the gateway documentation when changing gateway-specific behaviour.
