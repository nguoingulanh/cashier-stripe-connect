<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Exceptions\StripeConnectException;
use PHPUnit\Framework\Assert as PHPUnit;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\AccountSession;
use Stripe\Balance;
use Stripe\Collection;
use Stripe\LoginLink;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Transfer;
use Stripe\TransferReversal;

/**
 * In-memory Stripe gateway. Enable with CashierConnect::fake().
 */
final class FakeStripeGateway implements StripeGateway
{
    /** @var array<string, array<string, mixed>> */
    private array $accounts = [];

    /** @var list<array{method: string, params: array<string, mixed>, options: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, StripeConnectException> */
    private array $failures = [];

    // Fake state helpers ---------------------------------------------------------

    /**
     * Mark the account as fully onboarded (charges and payouts enabled).
     */
    public function completeOnboarding(string $accountId): self
    {
        return $this->setAccount($accountId, [
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => $this->requirements(),
        ]);
    }

    /**
     * Mark the account as restricted with the given past-due fields.
     *
     * @param  list<string>  $pastDue
     */
    public function restrict(string $accountId, array $pastDue = ['external_account'], ?string $reason = 'requirements.past_due'): self
    {
        return $this->setAccount($accountId, [
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'requirements' => $this->requirements(currentlyDue: $pastDue, pastDue: $pastDue, disabledReason: $reason),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setAccount(string $accountId, array $attributes): self
    {
        $this->accounts[$accountId] = array_replace($this->accounts[$accountId] ?? $this->newAccount($accountId), $attributes);

        return $this;
    }

    /**
     * Make the next call to $method throw a Stripe error.
     */
    public function failNext(string $method, string $message = 'Fake Stripe error', ?string $code = null): self
    {
        $exception = new StripeConnectException($message, 400);
        $exception->stripeCode = $code;

        $this->failures[$method] = $exception;

        return $this;
    }

    // Gateway ----------------------------------------------------------------------

    public function createAccount(array $params, array $options = []): Account
    {
        $this->record(__FUNCTION__, $params, $options);

        $id = 'acct_'.Str::random(16);

        $this->accounts[$id] = array_replace($this->newAccount($id), array_intersect_key($params, array_flip([
            'type', 'country', 'email', 'default_currency', 'metadata',
        ])));

        return Account::constructFrom($this->accounts[$id]);
    }

    public function retrieveAccount(string $accountId): Account
    {
        $this->record(__FUNCTION__, ['account' => $accountId]);

        return Account::constructFrom($this->accountOrFail($accountId));
    }

    public function updateAccount(string $accountId, array $params): Account
    {
        $this->record(__FUNCTION__, ['account' => $accountId] + $params);

        $this->accounts[$accountId] = array_replace($this->accountOrFail($accountId), $params);

        return Account::constructFrom($this->accounts[$accountId]);
    }

    public function deleteAccount(string $accountId): void
    {
        $this->record(__FUNCTION__, ['account' => $accountId]);
        $this->accountOrFail($accountId);

        unset($this->accounts[$accountId]);
    }

    public function createAccountLink(array $params): AccountLink
    {
        $this->record(__FUNCTION__, $params);

        return AccountLink::constructFrom([
            'object' => 'account_link',
            'url' => "https://connect.stripe.test/setup/{$params['type']}/{$params['account']}/".Str::random(8),
            'expires_at' => time() + 300,
        ]);
    }

    public function createLoginLink(string $accountId): LoginLink
    {
        $this->record(__FUNCTION__, ['account' => $accountId]);

        return LoginLink::constructFrom(['object' => 'login_link', 'url' => "https://connect.stripe.test/express/{$accountId}"]);
    }

    public function createAccountSession(array $params): AccountSession
    {
        $this->record(__FUNCTION__, $params);

        return AccountSession::constructFrom([
            'object' => 'account_session',
            'account' => $params['account'],
            'client_secret' => 'accs_secret_'.Str::random(16),
            'components' => $params['components'] ?? [],
            'expires_at' => time() + 1800,
        ]);
    }

    public function createTransfer(array $params, array $options = []): Transfer
    {
        $this->record(__FUNCTION__, $params, $options);

        return Transfer::constructFrom(['id' => 'tr_'.Str::random(16), 'object' => 'transfer', 'reversed' => false] + $params);
    }

    public function reverseTransfer(string $transferId, array $params = [], array $options = []): TransferReversal
    {
        $this->record(__FUNCTION__, ['transfer' => $transferId] + $params, $options);

        return TransferReversal::constructFrom(['id' => 'trr_'.Str::random(16), 'object' => 'transfer_reversal', 'transfer' => $transferId] + $params);
    }

    public function listTransfers(array $params = []): Collection
    {
        $this->record(__FUNCTION__, $params);

        $transfers = collect($this->calls)
            ->where('method', 'createTransfer')
            ->filter(fn (array $call) => ! isset($params['destination']) || $call['params']['destination'] === $params['destination'])
            ->map(fn (array $call) => ['object' => 'transfer'] + $call['params'])
            ->values()
            ->all();

        return Collection::constructFrom(['object' => 'list', 'data' => $transfers, 'has_more' => false]);
    }

    public function retrieveBalance(string $accountId): Balance
    {
        $this->record(__FUNCTION__, ['account' => $accountId]);

        return Balance::constructFrom([
            'object' => 'balance',
            'available' => [['amount' => 0, 'currency' => 'usd']],
            'pending' => [['amount' => 0, 'currency' => 'usd']],
        ]);
    }

    public function createPayout(string $accountId, array $params, array $options = []): Payout
    {
        $this->record(__FUNCTION__, ['account' => $accountId] + $params, $options);

        return Payout::constructFrom(['id' => 'po_'.Str::random(16), 'object' => 'payout', 'status' => 'pending'] + $params);
    }

    public function listPayouts(string $accountId, array $params = []): Collection
    {
        $this->record(__FUNCTION__, ['account' => $accountId] + $params);

        $payouts = collect($this->calls)
            ->where('method', 'createPayout')
            ->filter(fn (array $call) => $call['params']['account'] === $accountId)
            ->map(fn (array $call) => ['object' => 'payout'] + $call['params'])
            ->values()
            ->all();

        return Collection::constructFrom(['object' => 'list', 'data' => $payouts, 'has_more' => false]);
    }

    public function createRefund(array $params, array $options = []): Refund
    {
        $this->record(__FUNCTION__, $params, $options);

        return Refund::constructFrom(['id' => 're_'.Str::random(16), 'object' => 'refund', 'status' => 'succeeded'] + $params);
    }

    public function client(?string $accountId = null): StripeClient
    {
        throw new LogicException('The raw Stripe client is not available while CashierConnect is faked.');
    }

    // Assertions -------------------------------------------------------------------

    /**
     * @return list<array{method: string, params: array<string, mixed>, options: array<string, mixed>}>
     */
    public function calls(?string $method = null): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $method === null || $call['method'] === $method));
    }

    /**
     * @param  (callable(array<string, mixed>, array<string, mixed>): bool)|null  $callback
     */
    public function assertCalled(string $method, ?callable $callback = null): self
    {
        $matching = array_filter($this->calls($method), fn (array $call) => $callback === null || $callback($call['params'], $call['options']));

        PHPUnit::assertNotEmpty($matching, "Expected Stripe call [{$method}] was not made.");

        return $this;
    }

    public function assertNotCalled(string $method): self
    {
        PHPUnit::assertEmpty($this->calls($method), "Unexpected Stripe call [{$method}] was made.");

        return $this;
    }

    public function assertAccountCreatedFor(Model $owner): self
    {
        return $this->assertCalled('createAccount', fn (array $params) => ($params['metadata']['connectable_type'] ?? null) === $owner->getMorphClass()
            && ($params['metadata']['connectable_id'] ?? null) === (string) $owner->getKey());
    }

    public function assertTransferred(string $accountId, ?int $amount = null): self
    {
        return $this->assertCalled('createTransfer', fn (array $params) => $params['destination'] === $accountId
            && ($amount === null || $params['amount'] === $amount));
    }

    public function assertPayoutCreated(string $accountId, ?int $amount = null): self
    {
        return $this->assertCalled('createPayout', fn (array $params) => $params['account'] === $accountId
            && ($amount === null || $params['amount'] === $amount));
    }

    public function assertRefunded(string $paymentIntent, ?int $amount = null): self
    {
        return $this->assertCalled('createRefund', fn (array $params) => $params['payment_intent'] === $paymentIntent
            && ($amount === null || ($params['amount'] ?? null) === $amount));
    }

    // Internals --------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    private function record(string $method, array $params, array $options = []): void
    {
        if (isset($this->failures[$method])) {
            $exception = $this->failures[$method];
            unset($this->failures[$method]);

            throw $exception;
        }

        $this->calls[] = ['method' => $method, 'params' => $params, 'options' => $options];
    }

    /** @return array<string, mixed> */
    private function accountOrFail(string $accountId): array
    {
        if (! isset($this->accounts[$accountId])) {
            $exception = new StripeConnectException("No such account: '{$accountId}'", 404);
            $exception->stripeCode = 'resource_missing';

            throw $exception;
        }

        return $this->accounts[$accountId];
    }

    /** @return array<string, mixed> */
    private function newAccount(string $id): array
    {
        return [
            'id' => $id,
            'object' => 'account',
            'type' => 'express',
            'country' => 'US',
            'default_currency' => 'usd',
            'email' => null,
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'capabilities' => [],
            'requirements' => $this->requirements(currentlyDue: ['business_type', 'external_account', 'tos_acceptance.date']),
            'metadata' => [],
        ];
    }

    /**
     * @param  list<string>  $currentlyDue
     * @param  list<string>  $pastDue
     * @return array<string, mixed>
     */
    private function requirements(array $currentlyDue = [], array $pastDue = [], ?string $disabledReason = null): array
    {
        return [
            'currently_due' => $currentlyDue,
            'eventually_due' => $currentlyDue,
            'past_due' => $pastDue,
            'pending_verification' => [],
            'disabled_reason' => $disabledReason,
            'current_deadline' => null,
            'errors' => [],
        ];
    }
}
