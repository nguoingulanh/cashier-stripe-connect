<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Exceptions;

use Illuminate\Database\Eloquent\Model;

class AccountAlreadyExists extends StripeConnectException
{
    public static function forOwner(Model $owner, string $accountId): self
    {
        return new self(sprintf(
            '%s [%s] already has connected account [%s]. Enable "cashier-connect.multiple_accounts" to allow more.',
            $owner::class,
            (string) $owner->getKey(),
            $accountId,
        ));
    }
}
