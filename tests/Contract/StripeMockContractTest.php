<?php

/**
 * Runs every gateway call against stripe-mock, which validates requests
 * against Stripe's OpenAPI spec. Start it with:
 *
 *     docker run --rm -p 12111:12111 stripe/stripe-mock:latest
 *     STRIPE_MOCK_URL=http://localhost:12111 vendor/bin/pest --testsuite=Contract
 */

use Laravel\Cashier\Cashier;
use Nguoingulanh\CashierConnect\Facades\CashierConnect;
use Nguoingulanh\CashierConnect\Gateway\CashierStripeGateway;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\AccountSession;
use Stripe\Balance;
use Stripe\Collection;
use Stripe\LoginLink;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\Transfer;
use Stripe\TransferReversal;

beforeEach(function () {
    $url = getenv('STRIPE_MOCK_URL');

    if (! $url) {
        $this->markTestSkipped('Set STRIPE_MOCK_URL to run contract tests against stripe-mock.');
    }

    config(['cashier.secret' => 'sk_test_123']);
    $this->gateway = new CashierStripeGateway($url);
});

it('creates, retrieves, updates and deletes accounts', function () {
    $account = $this->gateway->createAccount([
        'type' => 'express',
        'country' => 'US',
        'email' => 'seller@example.com',
        'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]],
        'metadata' => ['connectable_type' => 'shop', 'connectable_id' => '1'],
    ], ['idempotency_key' => 'contract-create']);

    expect($account)->toBeInstanceOf(Account::class)
        ->and($this->gateway->retrieveAccount('acct_123'))->toBeInstanceOf(Account::class)
        ->and($this->gateway->updateAccount('acct_123', ['email' => 'new@example.com']))->toBeInstanceOf(Account::class);

    $this->gateway->deleteAccount('acct_123');
});

it('creates onboarding links, login links and account sessions', function () {
    expect($this->gateway->createAccountLink([
        'account' => 'acct_123',
        'type' => 'account_onboarding',
        'return_url' => 'https://example.com/return',
        'refresh_url' => 'https://example.com/refresh',
        'collection_options' => ['fields' => 'currently_due'],
    ]))->toBeInstanceOf(AccountLink::class)
        ->and($this->gateway->createLoginLink('acct_123'))->toBeInstanceOf(LoginLink::class)
        ->and($this->gateway->createAccountSession([
            'account' => 'acct_123',
            'components' => ['payments' => ['enabled' => true], 'payouts' => ['enabled' => true]],
        ]))->toBeInstanceOf(AccountSession::class);
});

it('moves funds', function () {
    expect($this->gateway->createTransfer(['amount' => 1000, 'currency' => 'usd', 'destination' => 'acct_123', 'transfer_group' => 'ORDER_1']))->toBeInstanceOf(Transfer::class)
        ->and($this->gateway->reverseTransfer('tr_123', ['amount' => 100]))->toBeInstanceOf(TransferReversal::class)
        ->and($this->gateway->listTransfers(['destination' => 'acct_123']))->toBeInstanceOf(Collection::class)
        ->and($this->gateway->retrieveBalance('acct_123'))->toBeInstanceOf(Balance::class)
        ->and($this->gateway->createPayout('acct_123', ['amount' => 500, 'currency' => 'usd']))->toBeInstanceOf(Payout::class)
        ->and($this->gateway->listPayouts('acct_123', ['limit' => 10]))->toBeInstanceOf(Collection::class)
        ->and($this->gateway->createRefund(['payment_intent' => 'pi_123', 'reverse_transfer' => true, 'refund_application_fee' => true]))->toBeInstanceOf(Refund::class);
});

it('sends payment options that stripe accepts', function () {
    $shop = shop();
    $account = ConnectedAccount::create([
        'connectable_type' => $shop->getMorphClass(),
        'connectable_id' => $shop->id,
        'stripe_account_id' => 'acct_123',
    ]);
    $stripe = $this->gateway->client();

    $stripe->paymentIntents->create(['amount' => 1000, 'currency' => 'usd'] + CashierConnect::destination($account)->fee(100)->onBehalfOf()->transferGroup('ORDER_1')->toArray());

    $stripe->checkout->sessions->create([
        'mode' => 'payment',
        'success_url' => 'https://example.com/success',
        'line_items' => [['price' => 'price_123', 'quantity' => 1]],
    ] + CashierConnect::destination($account)->fee(100)->forCheckout());

    $stripe->subscriptions->create([
        'customer' => 'cus_123',
        'items' => [['price' => 'price_123']],
    ] + CashierConnect::destination($account)->feePercent(10)->forSubscription());

    // Direct charge on the connected account.
    $this->gateway->client('acct_123')->paymentIntents->create(['amount' => 1000, 'currency' => 'usd', 'application_fee_amount' => 100]);
})->throwsNoExceptions();

it('creates the connect webhook endpoint', function () {
    $this->gateway->client()->webhookEndpoints->create([
        'url' => 'https://example.com/stripe/connect/webhook',
        'connect' => true,
        'enabled_events' => config('cashier-connect.webhook.events'),
        'api_version' => Cashier::STRIPE_VERSION,
    ]);
})->throwsNoExceptions();
