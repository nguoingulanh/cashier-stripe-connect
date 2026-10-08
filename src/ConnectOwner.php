<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect;

use Illuminate\Database\Eloquent\Model;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Nguoingulanh\CashierConnect\Services\AccountService;
use Nguoingulanh\CashierConnect\Services\FundsService;
use Nguoingulanh\CashierConnect\Services\OnboardingService;
use Nguoingulanh\CashierConnect\Services\PaymentOptions;
use Stripe\Account;
use Stripe\AccountSession;
use Stripe\Balance;
use Stripe\Collection;
use Stripe\Payout;
use Stripe\Transfer;
use Stripe\TransferReversal;

/**
 * Stripe Connect operations for one owner model (User, Shop, ...).
 *
 *     CashierConnect::for($shop)->onboardingUrl();
 */
final class ConnectOwner
{
    public function __construct(
        private readonly Model $owner,
        private readonly AccountService $accounts,
        private readonly OnboardingService $onboarding,
        private readonly FundsService $funds,
    ) {}

    public function owner(): Model
    {
        return $this->owner;
    }

    // Account ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params = [], array $options = []): ConnectedAccount
    {
        return $this->accounts->create($this->owner, $params, $options);
    }

    /**
     * The existing account, or a newly created one.
     *
     * @param  array<string, mixed>  $params
     */
    public function createOrGet(array $params = []): ConnectedAccount
    {
        return $this->account() ?? $this->create($params);
    }

    public function account(): ?ConnectedAccount
    {
        return $this->accounts->findFor($this->owner);
    }

    public function accountOrFail(): ConnectedAccount
    {
        return $this->accounts->findForOrFail($this->owner);
    }

    public function hasAccount(): bool
    {
        return $this->account() !== null;
    }

    public function stripeAccountId(): ?string
    {
        return $this->account()?->stripe_account_id;
    }

    public function asStripeAccount(): Account
    {
        return $this->accounts->retrieve($this->accountOrFail());
    }

    /** @param  array<string, mixed>  $params */
    public function update(array $params): ConnectedAccount
    {
        return $this->accounts->update($this->accountOrFail(), $params);
    }

    public function sync(): ConnectedAccount
    {
        return $this->accounts->sync($this->accountOrFail());
    }

    public function delete(): void
    {
        $this->accounts->delete($this->accountOrFail());
    }

    public function isReady(): bool
    {
        return $this->account()?->isReady() ?? false;
    }

    /** @return array<string, mixed> */
    public function requirements(): array
    {
        return $this->account()->requirements ?? [];
    }

    // Onboarding -------------------------------------------------------------

    /**
     * Hosted onboarding URL. Creates the connected account first if needed.
     *
     * @param  array<string, mixed>  $params  Extra AccountLink parameters.
     */
    public function onboardingUrl(?string $redirectTo = null, array $params = []): string
    {
        return $this->onboarding->onboardingUrl($this->createOrGet(), $redirectTo, $params);
    }

    /** @param  array<string, mixed>  $params */
    public function updateUrl(?string $redirectTo = null, array $params = []): string
    {
        return $this->onboarding->updateUrl($this->accountOrFail(), $redirectTo, $params);
    }

    public function dashboardUrl(): string
    {
        return $this->onboarding->dashboardUrl($this->accountOrFail());
    }

    /** @param  array<int|string, mixed>  $components */
    public function accountSession(array $components = ['account_onboarding']): AccountSession
    {
        return $this->onboarding->accountSession($this->createOrGet(), $components);
    }

    // Payments & funds -------------------------------------------------------

    public function destination(): PaymentOptions
    {
        return new PaymentOptions($this->accountOrFail());
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function transfer(int $amount, ?string $currency = null, array $params = [], array $options = []): Transfer
    {
        return $this->funds->transfer($this->accountOrFail(), $amount, $currency, $params, $options);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function reverseTransfer(string $transferId, ?int $amount = null, array $params = [], array $options = []): TransferReversal
    {
        return $this->funds->reverseTransfer($transferId, $amount, $params, $options);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<Transfer>
     */
    public function transfers(array $params = []): Collection
    {
        return $this->funds->transfers($this->accountOrFail(), $params);
    }

    public function balance(): Balance
    {
        return $this->funds->balance($this->accountOrFail());
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function payout(int $amount, ?string $currency = null, array $params = [], array $options = []): Payout
    {
        return $this->funds->payout($this->accountOrFail(), $amount, $currency, $params, $options);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<Payout>
     */
    public function payouts(array $params = []): Collection
    {
        return $this->funds->payouts($this->accountOrFail(), $params);
    }
}
