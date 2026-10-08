<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Contracts;

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
 * The single entry point for every Stripe API call made by the package.
 *
 * Swap the binding (e.g. with CashierConnect::fake()) to test without Stripe.
 */
interface StripeGateway
{
    /** @param  array<string, mixed>  $params
     *  @param  array<string, mixed>  $options */
    public function createAccount(array $params, array $options = []): Account;

    public function retrieveAccount(string $accountId): Account;

    /** @param  array<string, mixed>  $params */
    public function updateAccount(string $accountId, array $params): Account;

    public function deleteAccount(string $accountId): void;

    /** @param  array<string, mixed>  $params */
    public function createAccountLink(array $params): AccountLink;

    public function createLoginLink(string $accountId): LoginLink;

    /** @param  array<string, mixed>  $params */
    public function createAccountSession(array $params): AccountSession;

    /** @param  array<string, mixed>  $params
     *  @param  array<string, mixed>  $options */
    public function createTransfer(array $params, array $options = []): Transfer;

    /** @param  array<string, mixed>  $params
     *  @param  array<string, mixed>  $options */
    public function reverseTransfer(string $transferId, array $params = [], array $options = []): TransferReversal;

    /** @param  array<string, mixed>  $params
     *  @return Collection<Transfer> */
    public function listTransfers(array $params = []): Collection;

    public function retrieveBalance(string $accountId): Balance;

    /** @param  array<string, mixed>  $params
     *  @param  array<string, mixed>  $options */
    public function createPayout(string $accountId, array $params, array $options = []): Payout;

    /** @param  array<string, mixed>  $params
     *  @return Collection<Payout> */
    public function listPayouts(string $accountId, array $params = []): Collection;

    /** @param  array<string, mixed>  $params
     *  @param  array<string, mixed>  $options */
    public function createRefund(array $params, array $options = []): Refund;

    /**
     * Raw Stripe client, optionally scoped to a connected account (direct charges).
     */
    public function client(?string $accountId = null): StripeClient|ConnectedStripeClient;
}
