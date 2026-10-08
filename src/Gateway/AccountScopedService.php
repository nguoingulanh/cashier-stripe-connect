<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Gateway;

use ReflectionMethod;

/**
 * Wraps a Stripe service (e.g. PaymentIntentService) and injects the
 * "stripe_account" request option into the method's "$opts" argument.
 */
final class AccountScopedService
{
    public function __construct(
        private readonly object $service,
        private readonly string $accountId,
    ) {}

    public function __get(string $name): self
    {
        // Nested services, e.g. $client->checkout->sessions
        return new self($this->service->{$name}, $this->accountId);
    }

    /** @param  array<int, mixed>  $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $position = $this->optionsPosition($method);

        $arguments = array_pad($arguments, $position + 1, null);

        $options = $arguments[$position] ?? [];
        $options = is_array($options) ? $options : ['idempotency_key' => $options];
        $options['stripe_account'] = $this->accountId;

        $arguments[$position] = $options;

        return $this->service->{$method}(...$arguments);
    }

    private function optionsPosition(string $method): int
    {
        $reflection = new ReflectionMethod($this->service, $method);

        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->getName() === 'opts') {
                return $parameter->getPosition();
            }
        }

        return $reflection->getNumberOfParameters();
    }
}
