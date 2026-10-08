<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Webhooks\Handlers;

use Illuminate\Contracts\Events\Dispatcher;
use Nguoingulanh\CashierConnect\Events\ConnectPayoutCanceled;
use Nguoingulanh\CashierConnect\Events\ConnectPayoutFailed;
use Nguoingulanh\CashierConnect\Events\ConnectPayoutPaid;
use Nguoingulanh\CashierConnect\Services\AccountService;
use Nguoingulanh\CashierConnect\Webhooks\WebhookHandler;
use Stripe\Payout;

/**
 * payout.paid, payout.failed, payout.canceled on connected accounts.
 */
final class PayoutStatus implements WebhookHandler
{
    private const EVENTS = [
        'payout.paid' => ConnectPayoutPaid::class,
        'payout.failed' => ConnectPayoutFailed::class,
        'payout.canceled' => ConnectPayoutCanceled::class,
    ];

    public function __construct(
        private readonly AccountService $accounts,
        private readonly Dispatcher $events,
    ) {}

    public function handle(array $payload): void
    {
        $event = self::EVENTS[$payload['type'] ?? ''] ?? null;
        $accountId = $payload['account'] ?? null;

        if ($event === null || ! is_string($accountId) || ! $account = $this->accounts->findByStripeId($accountId)) {
            return;
        }

        $this->events->dispatch(new $event($account, Payout::constructFrom($payload['data']['object'])));
    }
}
