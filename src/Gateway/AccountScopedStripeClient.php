<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Gateway;

use Nguoingulanh\CashierConnect\Contracts\ConnectedStripeClient;
use Stripe\StripeClient;

/**
 * Proxies a StripeClient and adds the "Stripe-Account" header to every request.
 *
 *     $client->paymentIntents->create([...]);   // made on the connected account
 *
 * @mixin StripeClient
 */
final class AccountScopedStripeClient implements ConnectedStripeClient
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly string $accountId,
    ) {}

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function __get(string $name): AccountScopedService
    {
        return new AccountScopedService($this->client->{$name}, $this->accountId);
    }
}
