<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Contracts;

/**
 * Marker for a Stripe client whose requests are made on a connected account.
 */
interface ConnectedStripeClient
{
    public function accountId(): string;
}
