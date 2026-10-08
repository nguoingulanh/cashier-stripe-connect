<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Nguoingulanh\CashierConnect\CashierConnect;
use Nguoingulanh\CashierConnect\ConnectOwner;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Nguoingulanh\CashierConnect\Services\AccountService;
use Nguoingulanh\CashierConnect\Services\PaymentOptions;
use Stripe\Account;
use Stripe\AccountSession;
use Stripe\Balance;
use Stripe\Collection;
use Stripe\Payout;
use Stripe\Transfer;
use Stripe\TransferReversal;

/**
 * Optional sugar for models that own a Stripe connected account.
 *
 * @mixin Model
 */
trait ConnectBillable
{
    /** @return MorphOne<ConnectedAccount, $this> */
    public function connectAccount(): MorphOne
    {
        return $this->morphOne(AccountService::model(), 'connectable')->latestOfMany();
    }

    /** @return MorphMany<ConnectedAccount, $this> */
    public function connectAccounts(): MorphMany
    {
        return $this->morphMany(AccountService::model(), 'connectable');
    }

    public function connect(): ConnectOwner
    {
        return app(CashierConnect::class)->for($this);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function createConnectAccount(array $params = [], array $options = []): ConnectedAccount
    {
        return $this->connect()->create($params, $options);
    }

    public function hasConnectAccount(): bool
    {
        return $this->connect()->hasAccount();
    }

    public function connectAccountId(): ?string
    {
        return $this->connect()->stripeAccountId();
    }

    public function asStripeConnectAccount(): Account
    {
        return $this->connect()->asStripeAccount();
    }

    /** @param  array<string, mixed>  $params */
    public function updateConnectAccount(array $params): ConnectedAccount
    {
        return $this->connect()->update($params);
    }

    public function syncConnectAccount(): ConnectedAccount
    {
        return $this->connect()->sync();
    }

    public function deleteConnectAccount(): void
    {
        $this->connect()->delete();
    }

    public function isConnectReady(): bool
    {
        return $this->connect()->isReady();
    }

    /** @return array<string, mixed> */
    public function connectRequirements(): array
    {
        return $this->connect()->requirements();
    }

    public function connectOnboardingUrl(?string $redirectTo = null): string
    {
        return $this->connect()->onboardingUrl($redirectTo);
    }

    public function connectUpdateUrl(?string $redirectTo = null): string
    {
        return $this->connect()->updateUrl($redirectTo);
    }

    public function connectDashboardUrl(): string
    {
        return $this->connect()->dashboardUrl();
    }

    /** @param  array<int|string, mixed>  $components */
    public function connectAccountSession(array $components = ['account_onboarding']): AccountSession
    {
        return $this->connect()->accountSession($components);
    }

    public function connectDestination(): PaymentOptions
    {
        return $this->connect()->destination();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function transferToConnect(int $amount, ?string $currency = null, array $params = [], array $options = []): Transfer
    {
        return $this->connect()->transfer($amount, $currency, $params, $options);
    }

    public function reverseConnectTransfer(string $transferId, ?int $amount = null): TransferReversal
    {
        return $this->connect()->reverseTransfer($transferId, $amount);
    }

    public function connectBalance(): Balance
    {
        return $this->connect()->balance();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function connectPayout(int $amount, ?string $currency = null, array $params = [], array $options = []): Payout
    {
        return $this->connect()->payout($amount, $currency, $params, $options);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<Payout>
     */
    public function connectPayouts(array $params = []): Collection
    {
        return $this->connect()->payouts($params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<Transfer>
     */
    public function connectTransfers(array $params = []): Collection
    {
        return $this->connect()->transfers($params);
    }
}
