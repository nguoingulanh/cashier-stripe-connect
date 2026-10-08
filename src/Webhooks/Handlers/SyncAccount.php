<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Webhooks\Handlers;

use Illuminate\Contracts\Events\Dispatcher;
use Nguoingulanh\CashierConnect\Events\ConnectCapabilityUpdated;
use Nguoingulanh\CashierConnect\Services\AccountService;
use Nguoingulanh\CashierConnect\Webhooks\WebhookHandler;

/**
 * account.updated, capability.updated, person.updated.
 *
 * The account is re-fetched from Stripe instead of trusting the payload,
 * so events delivered out of order still converge to the latest state.
 */
final class SyncAccount implements WebhookHandler
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly Dispatcher $events,
    ) {}

    public function handle(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];

        $accountId = $payload['account']
            ?? (($object['object'] ?? null) === 'account' ? $object['id'] : null);

        if (! is_string($accountId) || ! $account = $this->accounts->findByStripeId($accountId)) {
            return;
        }

        $this->accounts->sync($account);

        if (($payload['type'] ?? null) === 'capability.updated') {
            $this->events->dispatch(new ConnectCapabilityUpdated(
                $account,
                (string) ($object['id'] ?? ''),
                (string) ($object['status'] ?? ''),
            ));
        }
    }
}
