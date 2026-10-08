<?php

use Nguoingulanh\CashierConnect\Facades\CashierConnect;

beforeEach(function () {
    fakeStripe();
    $this->shop = shop();
    $this->accountId = $this->shop->createConnectAccount()->stripe_account_id;
});

it('builds destination charge options with a fixed fee', function () {
    expect(CashierConnect::destination($this->shop)->fee(100)->toArray())->toBe([
        'transfer_data' => ['destination' => $this->accountId],
        'application_fee_amount' => 100,
    ]);
});

it('computes a percentage fee from the charge amount', function () {
    expect(CashierConnect::destination($this->accountId)->feePercent(12.5)->toArray(1000)['application_fee_amount'])->toBe(125);
});

it('requires the amount for percentage fees on one-off payments', function () {
    CashierConnect::destination($this->shop)->feePercent(10)->toArray();
})->throws(LogicException::class);

it('supports on_behalf_of, transfer amount, transfer group and extra params', function () {
    $options = $this->shop->connectDestination()
        ->transferAmount(900)
        ->onBehalfOf()
        ->transferGroup('ORDER_1')
        ->with(['metadata' => ['order' => 1]])
        ->toArray();

    expect($options)->toBe([
        'transfer_data' => ['destination' => $this->accountId, 'amount' => 900],
        'on_behalf_of' => $this->accountId,
        'transfer_group' => 'ORDER_1',
        'metadata' => ['order' => 1],
    ]);
});

it('wraps options for checkout sessions', function () {
    expect(CashierConnect::destination($this->shop)->fee(50)->forCheckout())->toBe([
        'payment_intent_data' => [
            'transfer_data' => ['destination' => $this->accountId],
            'application_fee_amount' => 50,
        ],
    ]);
});

it('builds subscription options', function () {
    expect(CashierConnect::destination($this->shop)->feePercent(10)->forSubscription())->toBe([
        'transfer_data' => ['destination' => $this->accountId],
        'application_fee_percent' => 10.0,
    ])->and(CashierConnect::destination($this->shop)->feePercent(10)->forSubscriptionCheckout())->toHaveKey('subscription_data');
});

it('rejects fixed fees on subscriptions', function () {
    CashierConnect::destination($this->shop)->fee(100)->forSubscription();
})->throws(LogicException::class);

it('rejects invalid percentages', function () {
    CashierConnect::destination($this->shop)->feePercent(120);
})->throws(LogicException::class);

it('rejects values that are not account ids', function () {
    CashierConnect::destination('cus_123');
})->throws(InvalidArgumentException::class);
