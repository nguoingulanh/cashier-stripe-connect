# Contributing

Thanks for helping! Please open an issue before large changes.

```bash
composer install
composer test          # Pest (Unit + Feature)
composer lint          # Laravel Pint
composer analyse       # Larastan

# Contract tests against stripe-mock
docker run --rm -p 12111:12111 stripe/stripe-mock:latest
STRIPE_MOCK_URL=http://localhost:12111 vendor/bin/pest --testsuite=Contract
```

Every pull request must keep CI green: tests on all supported PHP / Laravel versions, Pint, Larastan and the fresh-install test. Add a line to the `[Unreleased]` section of `CHANGELOG.md`.
