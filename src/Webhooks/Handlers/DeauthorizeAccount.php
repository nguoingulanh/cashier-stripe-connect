<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Webhooks\Handlers;

use Nguoingulanh\CashierConnect\Services\AccountService;
use Nguoingulanh\CashierConnect\Webhooks\WebhookHandler;

/**
 * account.application.deauthorized: the platform lost access to the account.
 */
final class DeauthorizeAccount implements WebhookHandler
{
    public function __construct(private readonly AccountService $accounts) {}

    public function handle(array $payload): void
    {
        $accountId = $payload['account'] ?? null;

        if (is_string($accountId) && $account = $this->accounts->findByStripeId($accountId)) {
            $this->accounts->markDeauthorized($account);
        }
    }
}
