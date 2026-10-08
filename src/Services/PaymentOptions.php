<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Services;

use LogicException;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;

/**
 * Builds Stripe parameters that route a Cashier payment to a connected account
 * (destination charges), for use with charge(), checkout() and subscriptions.
 *
 *     $user->charge(1000, $pm, CashierConnect::destination($shop)->fee(100)->toArray());
 */
final class PaymentOptions
{
    private ?int $feeAmount = null;

    private ?float $feePercent = null;

    private ?int $transferAmount = null;

    private bool $onBehalfOf = false;

    private ?string $transferGroup = null;

    /** @var array<string, mixed> */
    private array $extra = [];

    public function __construct(private readonly ConnectedAccount $account) {}

    /**
     * Fixed application fee kept by the platform, in the smallest currency unit.
     */
    public function fee(int $amount): self
    {
        $this->feeAmount = $amount;

        return $this;
    }

    /**
     * Application fee as a percentage (subscriptions, or charges with a known amount).
     */
    public function feePercent(float $percent): self
    {
        if ($percent < 0 || $percent > 100) {
            throw new LogicException('The application fee percent must be between 0 and 100.');
        }

        $this->feePercent = $percent;

        return $this;
    }

    /**
     * Transfer only this amount to the connected account instead of the full charge.
     */
    public function transferAmount(int $amount): self
    {
        $this->transferAmount = $amount;

        return $this;
    }

    /**
     * Make the connected account the merchant of record (settlement, statement descriptor).
     */
    public function onBehalfOf(bool $value = true): self
    {
        $this->onBehalfOf = $value;

        return $this;
    }

    public function transferGroup(string $group): self
    {
        $this->transferGroup = $group;

        return $this;
    }

    /** @param  array<string, mixed>  $params */
    public function with(array $params): self
    {
        $this->extra = array_replace_recursive($this->extra, $params);

        return $this;
    }

    /**
     * PaymentIntent parameters, e.g. for Billable::charge() / pay().
     *
     * @param  int|null  $amount  The charge amount, required with feePercent().
     * @return array<string, mixed>
     */
    public function toArray(?int $amount = null): array
    {
        $fee = $this->feeAmount;

        if ($fee === null && $this->feePercent !== null) {
            if ($amount === null) {
                throw new LogicException('feePercent() on a one-off payment needs the charge amount: toArray($amount), or use fee().');
            }

            $fee = (int) round($amount * $this->feePercent / 100);
        }

        return array_replace_recursive(array_filter([
            'transfer_data' => $this->transferData(),
            'application_fee_amount' => $fee,
            'on_behalf_of' => $this->onBehalfOf ? $this->account->stripe_account_id : null,
            'transfer_group' => $this->transferGroup,
        ], fn ($value) => $value !== null), $this->extra);
    }

    /**
     * Checkout Session options for a one-off payment (mode=payment).
     *
     * @return array<string, mixed>
     */
    public function forCheckout(?int $amount = null): array
    {
        return ['payment_intent_data' => $this->toArray($amount)];
    }

    /**
     * Options for SubscriptionBuilder::create($pm, [], $options).
     *
     * @return array<string, mixed>
     */
    public function forSubscription(): array
    {
        if ($this->feeAmount !== null) {
            throw new LogicException('Subscriptions only support a percentage fee: use feePercent().');
        }

        return array_replace_recursive(array_filter([
            'transfer_data' => $this->transferData(percentOfInvoice: true),
            'application_fee_percent' => $this->feePercent,
            'on_behalf_of' => $this->onBehalfOf ? $this->account->stripe_account_id : null,
        ], fn ($value) => $value !== null), $this->extra);
    }

    /**
     * Checkout Session options for a subscription (mode=subscription).
     *
     * @return array<string, mixed>
     */
    public function forSubscriptionCheckout(): array
    {
        return ['subscription_data' => $this->forSubscription()];
    }

    /** @return array<string, mixed> */
    private function transferData(bool $percentOfInvoice = false): array
    {
        $data = ['destination' => $this->account->stripe_account_id];

        if ($this->transferAmount !== null) {
            if ($percentOfInvoice) {
                throw new LogicException('Subscriptions do not support transferAmount(); use feePercent().');
            }

            $data['amount'] = $this->transferAmount;
        }

        return $data;
    }
}
