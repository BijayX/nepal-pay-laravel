# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - Unreleased

### Added

- Khalti KPG-2 web checkout gateway: initiate, verify, lookup (including HTTP 400 lookup
  bodies for expired and canceled payments).
- eSewa ePay v2 gateway: signed form generation, callback signature verification, status API.
- Unified `PaymentGateway` contract, `NepalPay` facade and manager with `extend()` support.
- Immutable DTOs (`PaymentRequest`, `ExpectedPayment`, `InitiatedPayment`, `PaymentResult`, ...)
  with validation; money handled as integer paisa.
- `PaymentStatus` enum shared with the Node.js package.
- Exception hierarchy with stable error codes (`AMOUNT_MISMATCH`, `INVALID_SIGNATURE`, ...).
- HTTP client wrapper with timeouts and retries for idempotent reads only.
- `GatewayRedirectResponse` (Responsable) with a self-submitting eSewa form view.
- `PaymentInitiated` and `PaymentVerified` events.
