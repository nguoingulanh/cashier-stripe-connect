<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Exceptions;

class InvalidConnectWebhook extends StripeConnectException
{
    public static function missingSecret(): self
    {
        return new self('STRIPE_CONNECT_WEBHOOK_SECRET is not configured. Run `php artisan cashier-connect:webhook` to create the endpoint and get the secret.');
    }
}
