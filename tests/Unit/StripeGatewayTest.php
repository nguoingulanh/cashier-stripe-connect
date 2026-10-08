<?php

use Nguoingulanh\CashierConnect\Exceptions\StripeConnectException;
use Nguoingulanh\CashierConnect\Facades\CashierConnect;
use Nguoingulanh\CashierConnect\Gateway\CashierStripeGateway;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Nguoingulanh\CashierConnect\Tests\Fixtures\RecordingHttpClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

function recordStripe(array $response = ['id' => 'obj_123', 'object' => 'payment_intent']): RecordingHttpClient
{
    $client = new RecordingHttpClient($response);
    ApiRequestor::setHttpClient($client);

    return $client;
}

afterEach(fn () => ApiRequestor::setHttpClient(null));

function localAccount(string $id = 'acct_123'): ConnectedAccount
{
    $shop = shop();

    return ConnectedAccount::create([
        'connectable_type' => $shop->getMorphClass(),
        'connectable_id' => $shop->id,
        'stripe_account_id' => $id,
    ]);
}

it('sends direct charge requests on the connected account', function () {
    $http = recordStripe();
    localAccount('acct_direct');

    CashierConnect::onBehalfOf('acct_direct')->paymentIntents->create(['amount' => 1000, 'currency' => 'usd']);

    expect($http->last()['url'])->toEndWith('/v1/payment_intents')
        ->and($http->lastHeader('Stripe-Account'))->toBe('acct_direct');
});

it('keeps caller options and supports nested services and retrieve signatures', function () {
    $http = recordStripe(['id' => 'cs_1', 'object' => 'checkout.session']);
    localAccount('acct_nested');
    $client = CashierConnect::onBehalfOf('acct_nested');

    $client->checkout->sessions->create(['mode' => 'payment'], ['idempotency_key' => 'abc']);
    expect($http->lastHeader('Stripe-Account'))->toBe('acct_nested')
        ->and($http->lastHeader('Idempotency-Key'))->toBe('abc');

    $client->paymentIntents->retrieve('pi_1');
    expect($http->last()['url'])->toEndWith('/v1/payment_intents/pi_1')
        ->and($http->lastHeader('Stripe-Account'))->toBe('acct_nested');
});

it('scopes balance and payouts to the connected account', function () {
    $http = recordStripe(['object' => 'balance', 'available' => [], 'pending' => []]);
    $gateway = new CashierStripeGateway;

    $gateway->retrieveBalance('acct_bal');
    expect($http->lastHeader('Stripe-Account'))->toBe('acct_bal');

    $http = recordStripe(['id' => 'po_1', 'object' => 'payout']);
    $gateway->createPayout('acct_bal', ['amount' => 100, 'currency' => 'usd'], ['idempotency_key' => 'p1']);
    expect($http->lastHeader('Stripe-Account'))->toBe('acct_bal')
        ->and($http->lastHeader('Idempotency-Key'))->toBe('p1');
});

it('creates transfers on the platform', function () {
    $http = recordStripe(['id' => 'tr_1', 'object' => 'transfer']);

    (new CashierStripeGateway)->createTransfer(['amount' => 100, 'currency' => 'usd', 'destination' => 'acct_x']);

    // Older stripe-php versions send an empty header, which Stripe ignores.
    expect($http->lastHeader('Stripe-Account'))->toBeEmpty()
        ->and($http->last()['params'])->toMatchArray(['destination' => 'acct_x']);
});

it('uses the configured api base', function () {
    $http = recordStripe(['id' => 'acct_1', 'object' => 'account']);

    (new CashierStripeGateway('http://localhost:12111'))->retrieveAccount('acct_1');

    expect($http->last()['url'])->toBe('http://localhost:12111/v1/accounts/acct_1');
});

it('wraps stripe errors with code and request id', function () {
    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            return [json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such account']]), 404, ['request-id' => 'req_1']];
        }
    });

    try {
        (new CashierStripeGateway)->retrieveAccount('acct_missing');
        $this->fail('Expected exception');
    } catch (StripeConnectException $e) {
        expect($e->stripeCode)->toBe('resource_missing')
            ->and($e->getCode())->toBe(404)
            ->and($e->getMessage())->toContain('No such account');
    }
});
