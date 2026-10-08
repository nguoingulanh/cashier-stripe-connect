<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Cashier\Events\WebhookReceived;
use Nguoingulanh\CashierConnect\Events\ConnectApplicationFeeRefunded;
use Nguoingulanh\CashierConnect\Events\ConnectTransferCreated;
use Nguoingulanh\CashierConnect\Events\ConnectTransferReversed;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Nguoingulanh\CashierConnect\Services\AccountService;
use Stripe\ApplicationFee;
use Stripe\Transfer;

/**
 * Transfers and application fees live on the platform account, so Stripe
 * sends them to Cashier's regular webhook endpoint rather than the Connect one.
 * This listener turns them into Connect events.
 */
final class PlatformWebhookListener
{
    public const EVENTS = ['transfer.created', 'transfer.reversed', 'application_fee.refunded'];

    public function __construct(
        private readonly AccountService $accounts,
        private readonly Dispatcher $events,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $object = $payload['data']['object'] ?? null;

        if (! is_array($object)) {
            return;
        }

        match ($payload['type'] ?? null) {
            'transfer.created' => $this->events->dispatch(new ConnectTransferCreated(
                Transfer::constructFrom($object),
                $this->account($object['destination'] ?? null),
            )),
            'transfer.reversed' => $this->events->dispatch(new ConnectTransferReversed(
                Transfer::constructFrom($object),
                $this->account($object['destination'] ?? null),
            )),
            'application_fee.refunded' => $this->events->dispatch(new ConnectApplicationFeeRefunded(
                ApplicationFee::constructFrom($object),
                $this->account($object['account'] ?? null),
            )),
            default => null,
        };
    }

    private function account(mixed $id): ?ConnectedAccount
    {
        return is_string($id) ? $this->accounts->findByStripeId($id) : null;
    }
}
