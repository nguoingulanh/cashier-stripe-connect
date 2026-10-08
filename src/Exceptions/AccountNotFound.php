<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Exceptions;

use Illuminate\Database\Eloquent\Model;

class AccountNotFound extends StripeConnectException
{
    public static function forOwner(Model $owner): self
    {
        return new self(sprintf(
            '%s [%s] does not have a Stripe connected account yet. Call createConnectAccount() first.',
            $owner::class,
            (string) $owner->getKey(),
        ));
    }

    public static function forStripeId(string $accountId): self
    {
        return new self("No local connected account found for Stripe account [{$accountId}].");
    }
}
