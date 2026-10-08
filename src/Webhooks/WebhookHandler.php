<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Webhooks;

interface WebhookHandler
{
    /**
     * @param  array<string, mixed>  $payload  The full Stripe event.
     */
    public function handle(array $payload): void;
}
