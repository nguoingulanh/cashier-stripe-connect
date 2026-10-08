<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Nguoingulanh\CashierConnect\Enums\WebhookStatus;
use Nguoingulanh\CashierConnect\Events\ConnectAccountDeauthorized;
use Nguoingulanh\CashierConnect\Events\ConnectAccountReady;
use Nguoingulanh\CashierConnect\Events\ConnectCapabilityUpdated;
use Nguoingulanh\CashierConnect\Events\ConnectPayoutFailed;
use Nguoingulanh\CashierConnect\Events\ConnectPayoutPaid;
use Nguoingulanh\CashierConnect\Events\ConnectWebhookHandled;
use Nguoingulanh\CashierConnect\Events\ConnectWebhookReceived;
use Nguoingulanh\CashierConnect\Facades\CashierConnect;
use Nguoingulanh\CashierConnect\Models\ConnectWebhookEvent;
use Nguoingulanh\CashierConnect\Webhooks\Jobs\HandleConnectWebhook;
use Nguoingulanh\CashierConnect\Webhooks\WebhookHandler;

beforeEach(function () {
    $this->stripe = fakeStripe();
    $this->shop = shop();
    $this->account = $this->shop->createConnectAccount();
    $this->accountId = $this->account->stripe_account_id;
});

function postWebhook(array $event, string $secret = 'whsec_test', ?int $timestamp = null)
{
    [$payload, $headers] = signedWebhook($event, $secret, $timestamp);

    return test()->call('POST', '/stripe/connect/webhook', [], [], [], collect($headers)
        ->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])
        ->put('CONTENT_TYPE', 'application/json')
        ->all(), $payload);
}

function accountUpdated(string $accountId, array $extra = []): array
{
    return array_replace([
        'type' => 'account.updated',
        'account' => $accountId,
        'data' => ['object' => ['id' => $accountId, 'object' => 'account', 'charges_enabled' => true]],
    ], $extra);
}

it('rejects requests when the secret is not configured', function () {
    config(['cashier-connect.webhook.secret' => null]);

    postWebhook(accountUpdated($this->accountId))->assertStatus(500);

    expect(ConnectWebhookEvent::count())->toBe(0);
});

it('rejects invalid signatures', function () {
    postWebhook(accountUpdated($this->accountId), 'whsec_wrong')->assertStatus(400);

    expect(ConnectWebhookEvent::count())->toBe(0);
});

it('rejects requests outside the timestamp tolerance', function () {
    postWebhook(accountUpdated($this->accountId), timestamp: time() - 3600)->assertStatus(400);
});

it('rejects malformed payloads', function () {
    $this->call('POST', '/stripe/connect/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=x'], 'not json')
        ->assertStatus(400);
});

it('accepts any of several secrets during rotation', function () {
    config(['cashier-connect.webhook.secret' => 'whsec_old, whsec_new']);

    postWebhook(accountUpdated($this->accountId), 'whsec_new')->assertOk();
});

it('syncs the account from stripe on account.updated', function () {
    Event::fake([ConnectAccountReady::class, ConnectWebhookReceived::class, ConnectWebhookHandled::class]);
    $this->stripe->completeOnboarding($this->accountId);

    postWebhook(accountUpdated($this->accountId))->assertOk()->assertSee('Webhook handled.');

    expect($this->shop->isConnectReady())->toBeTrue()
        ->and(ConnectWebhookEvent::first())
        ->status->toBe(WebhookStatus::Processed)
        ->attempts->toBe(1)
        ->stripe_account_id->toBe($this->accountId);

    Event::assertDispatched(ConnectAccountReady::class);
    Event::assertDispatched(ConnectWebhookReceived::class);
    Event::assertDispatched(ConnectWebhookHandled::class);
});

it('re-fetches the account instead of trusting the payload', function () {
    // Payload claims charges are enabled, Stripe says otherwise (e.g. an older event).
    postWebhook(accountUpdated($this->accountId))->assertOk();

    expect($this->shop->isConnectReady())->toBeFalse();
    $this->stripe->assertCalled('retrieveAccount');
});

it('handles the same event only once', function () {
    $event = accountUpdated($this->accountId, ['id' => 'evt_duplicate']);

    postWebhook($event)->assertOk();
    postWebhook($event)->assertOk()->assertSee('already handled');

    expect($this->stripe->calls('retrieveAccount'))->toHaveCount(1)
        ->and(ConnectWebhookEvent::count())->toBe(1);
});

it('records failures, returns 500 and succeeds on retry', function () {
    $event = accountUpdated($this->accountId, ['id' => 'evt_retry']);
    $this->stripe->failNext('retrieveAccount', 'Stripe is down');

    postWebhook($event)->assertStatus(500);

    expect(ConnectWebhookEvent::first())
        ->status->toBe(WebhookStatus::Failed)
        ->last_error->toContain('Stripe is down');

    postWebhook($event)->assertOk();

    expect(ConnectWebhookEvent::first())
        ->status->toBe(WebhookStatus::Processed)
        ->attempts->toBe(2)
        ->last_error->toBeNull();
});

it('does not run an event that another worker is processing', function () {
    $event = accountUpdated($this->accountId, ['id' => 'evt_busy']);
    postWebhook($event);
    ConnectWebhookEvent::query()->update(['status' => WebhookStatus::Processing->value, 'updated_at' => now()]);

    postWebhook($event)->assertOk();

    expect($this->stripe->calls('retrieveAccount'))->toHaveCount(1);
});

it('reclaims events stuck in processing', function () {
    $event = accountUpdated($this->accountId, ['id' => 'evt_stuck']);
    postWebhook($event);
    ConnectWebhookEvent::query()->update(['status' => WebhookStatus::Processing->value, 'updated_at' => now()->subHour()]);

    postWebhook($event)->assertOk();

    expect($this->stripe->calls('retrieveAccount'))->toHaveCount(2);
});

it('acknowledges unknown event types and still fires generic events', function () {
    Event::fake([ConnectWebhookReceived::class]);

    postWebhook(['type' => 'balance.available', 'account' => $this->accountId, 'data' => ['object' => []]])->assertOk();

    Event::assertDispatched(ConnectWebhookReceived::class, fn ($e) => $e->payload['type'] === 'balance.available');
});

it('ignores unknown accounts', function () {
    postWebhook(accountUpdated('acct_unknown'))->assertOk();

    $this->stripe->assertNotCalled('retrieveAccount');
});

it('ignores test events on a live platform', function () {
    config(['cashier.secret' => 'sk_live_123']);

    postWebhook(accountUpdated($this->accountId))->assertOk()->assertSee('ignored');

    expect(ConnectWebhookEvent::count())->toBe(0);
});

it('marks accounts deauthorized', function () {
    Event::fake([ConnectAccountDeauthorized::class]);

    postWebhook([
        'type' => 'account.application.deauthorized',
        'account' => $this->accountId,
        'data' => ['object' => ['id' => 'ca_123', 'object' => 'application']],
    ])->assertOk();

    expect($this->account->fresh()->isDeauthorized())->toBeTrue();
    Event::assertDispatched(ConnectAccountDeauthorized::class);
    $this->stripe->assertNotCalled('retrieveAccount');
});

it('fires capability events', function () {
    Event::fake([ConnectCapabilityUpdated::class]);

    postWebhook([
        'type' => 'capability.updated',
        'account' => $this->accountId,
        'data' => ['object' => ['id' => 'card_payments', 'object' => 'capability', 'status' => 'active']],
    ])->assertOk();

    Event::assertDispatched(ConnectCapabilityUpdated::class, fn ($e) => $e->capability === 'card_payments' && $e->status === 'active');
});

it('fires payout events', function (string $type, string $event) {
    Event::fake([$event]);

    postWebhook([
        'type' => $type,
        'account' => $this->accountId,
        'data' => ['object' => ['id' => 'po_123', 'object' => 'payout', 'amount' => 1000, 'status' => 'failed']],
    ])->assertOk();

    Event::assertDispatched($event, fn ($e) => $e->payout->id === 'po_123' && $e->account->is($this->account));
})->with([
    ['payout.paid', ConnectPayoutPaid::class],
    ['payout.failed', ConnectPayoutFailed::class],
]);

it('queues events when a queue is configured', function () {
    Bus::fake();
    config(['cashier-connect.webhook.queue' => 'stripe']);

    postWebhook(accountUpdated($this->accountId))->assertOk()->assertSee('queued');

    Bus::assertDispatched(HandleConnectWebhook::class, fn ($job) => $job->queue === 'stripe');
    expect(ConnectWebhookEvent::first()->status)->toBe(WebhookStatus::Pending);
});

it('processes queued events in the job', function () {
    config(['cashier-connect.webhook.queue' => 'default']);
    $this->stripe->completeOnboarding($this->accountId);

    postWebhook(accountUpdated($this->accountId))->assertOk();

    expect($this->shop->isConnectReady())->toBeTrue()
        ->and(ConnectWebhookEvent::first()->status)->toBe(WebhookStatus::Processed);
});

it('lets the application override handlers', function () {
    $handler = new class implements WebhookHandler
    {
        public array $handled = [];

        public function handle(array $payload): void
        {
            $this->handled[] = $payload['type'];
        }
    };
    app()->instance($handler::class, $handler);

    CashierConnect::handleWebhookUsing('account.updated', $handler::class);
    CashierConnect::handleWebhookUsing('balance.available', fn (array $payload) => $handler->handled[] = 'closure');

    postWebhook(accountUpdated($this->accountId))->assertOk();
    postWebhook(['type' => 'balance.available', 'account' => $this->accountId, 'data' => ['object' => []]])->assertOk();

    expect($handler->handled)->toBe(['account.updated', 'closure']);
    $this->stripe->assertNotCalled('retrieveAccount');
});
