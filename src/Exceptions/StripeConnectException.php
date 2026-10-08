<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Exceptions;

use RuntimeException;
use Stripe\Exception\ApiErrorException;

/**
 * @phpstan-consistent-constructor
 */
class StripeConnectException extends RuntimeException
{
    public ?string $stripeCode = null;

    public ?string $requestId = null;

    public static function fromStripe(ApiErrorException $e): static
    {
        $exception = new static('Stripe Connect request failed: '.$e->getMessage(), (int) $e->getHttpStatus(), $e);
        $exception->stripeCode = $e->getStripeCode();
        $exception->requestId = $e->getRequestId();

        return $exception;
    }
}
