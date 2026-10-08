<?php

use Laravel\Cashier\Cashier;
use Nguoingulanh\CashierConnect\Facades\CashierConnect;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Nguoingulanh\CashierConnect\Tests\Fixtures\Customer;
use Nguoingulanh\CashierConnect\Tests\Fixtures\RecordingHttpClient;
use Stripe\ApiRequestor;

afterEach(fn () => ApiRequestor::setHttpClient(null));

function checkoutUrls(): array
{
    return ['success_url' => 'https://app.test/success', 'cancel_url' => 'https://app.test/cancel'];
}

beforeEach(function () {
    $shop = shop();

    $this->account = ConnectedAccount::create([
        'connectable_type' => $shop->getMorphClass(),
        'connectable_id' => $shop->id,
        'stripe_account_id' => 'acct_seller',
    ]);

    $this->customer = Customer::create(['name' => 'Buyer', 'email' => 'buyer@test.dev', 'stripe_id' => 'cus_buyer']);
});

it('passes destination charge options through Cashier::charge()', function () {
    $http = new RecordingHttpClient([
        'id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'usd',
    ]);
    ApiRequestor::setHttpClient($http);

    $this->customer->charge(10000, 'pm_card_visa', CashierConnect::destination($this->account)->fee(1000)->toArray());

    expect($http->last()['url'])->toEndWith('/v1/payment_intents')
        ->and($http->last()['params'])->toMatchArray([
            'amount' => 10000,
            'customer' => 'cus_buyer',
            'application_fee_amount' => 1000,
            'transfer_data' => ['destination' => 'acct_seller'],
        ]);
});

it('passes destination options through Cashier::checkout()', function () {
    $http = new RecordingHttpClient(['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test']);
    ApiRequestor::setHttpClient($http);

    $this->customer->checkout(['price_123' => 1], CashierConnect::destination($this->account)->fee(500)->forCheckout() + checkoutUrls());

    expect($http->last()['params']['payment_intent_data'])->toBe([
        'transfer_data' => ['destination' => 'acct_seller'],
        'application_fee_amount' => 500,
    ]);
});

it('merges with Cashier subscription checkout data', function () {
    $http = new RecordingHttpClient(['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test']);
    ApiRequestor::setHttpClient($http);

    $this->customer->newSubscription('default', 'price_monthly')
        ->checkout(CashierConnect::destination($this->account)->feePercent(10)->forSubscriptionCheckout() + checkoutUrls());

    $data = $http->last()['params']['subscription_data'];

    expect($data['transfer_data'])->toBe(['destination' => 'acct_seller'])
        ->and((float) $data['application_fee_percent'])->toBe(10.0)
        // Cashier's own subscription metadata is preserved.
        ->and($data['metadata'])->toHaveKey('name', 'default');
});

it('uses the same Stripe API version as Cashier', function () {
    $http = new RecordingHttpClient(['id' => 'acct_seller', 'object' => 'account']);
    ApiRequestor::setHttpClient($http);

    CashierConnect::accounts()->retrieve($this->account);

    expect($http->lastHeader('Stripe-Version'))->toBe(Cashier::STRIPE_VERSION);
});
