<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Services;

use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Stripe\Balance;
use Stripe\Collection;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\Transfer;
use Stripe\TransferReversal;

/**
 * Moves money between the platform and connected accounts.
 */
final class FundsService
{
    public function __construct(private readonly StripeGateway $gateway) {}

    /**
     * Separate charges & transfers: send funds from the platform balance.
     *
     * @param  array<string, mixed>  $params  e.g. transfer_group, source_transaction, metadata
     * @param  array<string, mixed>  $options  e.g. idempotency_key
     */
    public function transfer(ConnectedAccount $account, int $amount, ?string $currency = null, array $params = [], array $options = []): Transfer
    {
        return $this->gateway->createTransfer(array_merge([
            'amount' => $amount,
            'currency' => strtolower($currency ?? $this->defaultCurrency($account)),
            'destination' => $account->stripe_account_id,
        ], $params), $options);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function reverseTransfer(string $transferId, ?int $amount = null, array $params = [], array $options = []): TransferReversal
    {
        return $this->gateway->reverseTransfer($transferId, array_filter(['amount' => $amount]) + $params, $options);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<Transfer>
     */
    public function transfers(ConnectedAccount $account, array $params = []): Collection
    {
        return $this->gateway->listTransfers(['destination' => $account->stripe_account_id] + $params);
    }

    public function balance(ConnectedAccount $account): Balance
    {
        return $this->gateway->retrieveBalance($account->stripe_account_id);
    }

    /**
     * Pay out funds from the connected account's balance to its bank account.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function payout(ConnectedAccount $account, int $amount, ?string $currency = null, array $params = [], array $options = []): Payout
    {
        return $this->gateway->createPayout($account->stripe_account_id, array_merge([
            'amount' => $amount,
            'currency' => strtolower($currency ?? $this->defaultCurrency($account)),
        ], $params), $options);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<Payout>
     */
    public function payouts(ConnectedAccount $account, array $params = []): Collection
    {
        return $this->gateway->listPayouts($account->stripe_account_id, $params);
    }

    /**
     * Refund a payment, optionally pulling funds back from the connected account.
     *
     * @param  ConnectedAccount|null  $onAccount  Set for direct charges (refund created on the connected account).
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function refund(
        string $paymentIntent,
        ?int $amount = null,
        bool $reverseTransfer = false,
        bool $refundApplicationFee = false,
        ?ConnectedAccount $onAccount = null,
        array $params = [],
        array $options = [],
    ): Refund {
        $params = array_merge(array_filter([
            'payment_intent' => $paymentIntent,
            'amount' => $amount,
            'reverse_transfer' => $reverseTransfer ?: null,
            'refund_application_fee' => $refundApplicationFee ?: null,
        ], fn ($value) => $value !== null), $params);

        if ($onAccount) {
            $options['stripe_account'] = $onAccount->stripe_account_id;
        }

        return $this->gateway->createRefund($params, $options);
    }

    private function defaultCurrency(ConnectedAccount $account): string
    {
        return $account->default_currency ?? config('cashier.currency', 'usd');
    }
}
