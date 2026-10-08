<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Gateway;

use Laravel\Cashier\Cashier;
use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Exceptions\StripeConnectException;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\AccountSession;
use Stripe\Balance;
use Stripe\Collection;
use Stripe\Exception\ApiErrorException;
use Stripe\LoginLink;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Transfer;
use Stripe\TransferReversal;

final class CashierStripeGateway implements StripeGateway
{
    public function __construct(private readonly ?string $apiBase = null) {}

    public function createAccount(array $params, array $options = []): Account
    {
        return $this->call(fn () => $this->stripe()->accounts->create($params, $options ?: null));
    }

    public function retrieveAccount(string $accountId): Account
    {
        return $this->call(fn () => $this->stripe()->accounts->retrieve($accountId));
    }

    public function updateAccount(string $accountId, array $params): Account
    {
        return $this->call(fn () => $this->stripe()->accounts->update($accountId, $params));
    }

    public function deleteAccount(string $accountId): void
    {
        $this->call(fn () => $this->stripe()->accounts->delete($accountId));
    }

    public function createAccountLink(array $params): AccountLink
    {
        return $this->call(fn () => $this->stripe()->accountLinks->create($params));
    }

    public function createLoginLink(string $accountId): LoginLink
    {
        return $this->call(fn () => $this->stripe()->accounts->createLoginLink($accountId));
    }

    public function createAccountSession(array $params): AccountSession
    {
        return $this->call(fn () => $this->stripe()->accountSessions->create($params));
    }

    public function createTransfer(array $params, array $options = []): Transfer
    {
        return $this->call(fn () => $this->stripe()->transfers->create($params, $options ?: null));
    }

    public function reverseTransfer(string $transferId, array $params = [], array $options = []): TransferReversal
    {
        return $this->call(fn () => $this->stripe()->transfers->createReversal($transferId, $params ?: null, $options ?: null));
    }

    public function listTransfers(array $params = []): Collection
    {
        return $this->call(fn () => $this->stripe()->transfers->all($params));
    }

    public function retrieveBalance(string $accountId): Balance
    {
        return $this->call(fn () => $this->stripe()->balance->retrieve(null, ['stripe_account' => $accountId]));
    }

    public function createPayout(string $accountId, array $params, array $options = []): Payout
    {
        return $this->call(fn () => $this->stripe()->payouts->create($params, ['stripe_account' => $accountId] + $options));
    }

    public function listPayouts(string $accountId, array $params = []): Collection
    {
        return $this->call(fn () => $this->stripe()->payouts->all($params, ['stripe_account' => $accountId]));
    }

    public function createRefund(array $params, array $options = []): Refund
    {
        return $this->call(fn () => $this->stripe()->refunds->create($params, $options ?: null));
    }

    public function client(?string $accountId = null): StripeClient|AccountScopedStripeClient
    {
        return $accountId === null
            ? $this->stripe()
            : new AccountScopedStripeClient($this->stripe(), $accountId);
    }

    private function stripe(): StripeClient
    {
        return Cashier::stripe(array_filter(['api_base' => $this->apiBase]));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function call(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiErrorException $e) {
            throw StripeConnectException::fromStripe($e);
        }
    }
}
