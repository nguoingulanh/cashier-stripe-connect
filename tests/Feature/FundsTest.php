<?php

use Nguoingulanh\CashierConnect\Facades\CashierConnect;

beforeEach(function () {
    $this->stripe = fakeStripe();
    $this->shop = shop();
    $this->account = $this->shop->createConnectAccount();
});

it('transfers funds to the connected account', function () {
    $transfer = $this->shop->transferToConnect(5000, 'USD', ['transfer_group' => 'ORDER_1'], ['idempotency_key' => 'k1']);

    expect($transfer->amount)->toBe(5000)->and($transfer->currency)->toBe('usd');

    CashierConnect::assertTransferred($this->shop, 5000);
    $this->stripe->assertCalled('createTransfer', fn ($params, $options) => $params['transfer_group'] === 'ORDER_1' && $options === ['idempotency_key' => 'k1']);
});

it('uses the account default currency', function () {
    $this->shop->transferToConnect(100);

    $this->stripe->assertCalled('createTransfer', fn ($params) => $params['currency'] === 'usd');
});

it('reverses transfers and lists them', function () {
    $transfer = $this->shop->transferToConnect(100);

    $this->shop->reverseConnectTransfer($transfer->id, 40);

    $this->stripe->assertCalled('reverseTransfer', fn ($params) => $params['transfer'] === $transfer->id && $params['amount'] === 40);
    expect($this->shop->connectTransfers()->data)->toHaveCount(1);
});

it('reads the balance and creates payouts', function () {
    expect($this->shop->connectBalance()->available[0]->amount)->toBe(0);

    $this->shop->connectPayout(2500);

    CashierConnect::assertPayoutCreated($this->account->stripe_account_id, 2500);
    expect($this->shop->connectPayouts()->data)->toHaveCount(1);
});

it('refunds destination charges with transfer and fee reversal', function () {
    CashierConnect::refund('pi_123', 500, reverseTransfer: true, refundApplicationFee: true);

    CashierConnect::assertRefunded('pi_123', 500);
    $this->stripe->assertCalled('createRefund', fn ($params, $options) => $params['reverse_transfer'] === true
        && $params['refund_application_fee'] === true
        && $options === []);
});

it('refunds direct charges on the connected account', function () {
    CashierConnect::refund('pi_123', onAccount: $this->shop);

    $this->stripe->assertCalled('createRefund', fn ($params, $options) => ! isset($params['reverse_transfer'])
        && $options === ['stripe_account' => $this->account->stripe_account_id]);
});
