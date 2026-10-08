# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-08

First stable pre-1.0 release, so `composer require nguoingulanh/cashier-connect` works without a version constraint. Same features as 1.0.0-beta.1. The API may still change before 1.0.

## [1.0.0-beta.1] - 2026-10-08

### Added
- Connected accounts stored in a polymorphic table: create, sync, update, delete (Express, Standard, Custom or controller properties).
- Hosted onboarding links with signed return / refresh routes, Express dashboard login links and Account Sessions for embedded components.
- Destination charge, subscription and Checkout options builder; direct charges through an account-scoped Stripe client.
- Transfers, transfer reversals, balance, payouts and refunds (with transfer and application fee reversal).
- Connect webhook endpoint with signature verification (secret rotation), idempotent event storage, optional queueing and retries.
- Events: account created / updated / ready / restricted / deauthorized / deleted, capability updated, payout paid / failed / canceled, transfer created / reversed, application fee refunded.
- `connect.ready` middleware.
- Artisan commands: `install`, `webhook`, `sync`, `doctor`, `prune`, `replay`.
- `CashierConnect::fake()` with assertions for application tests.

### Fixed
- Syncing an unchanged account no longer fires `ConnectAccountUpdated` on MySQL (JSON key order).

[Unreleased]: https://github.com/nguoingulanh/cashier-stripe-connect/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/nguoingulanh/cashier-stripe-connect/releases/tag/v0.1.0
[1.0.0-beta.1]: https://github.com/nguoingulanh/cashier-stripe-connect/releases/tag/v1.0.0-beta.1
