<?php

use Nguoingulanh\CashierConnect\Enums\WebhookStatus;
use Nguoingulanh\CashierConnect\Models\ConnectWebhookEvent;

beforeEach(function () {
    $this->stripe = fakeStripe();
});

function storedEvent(array $attributes = []): ConnectWebhookEvent
{
    return ConnectWebhookEvent::create($attributes + [
        'stripe_event_id' => 'evt_'.uniqid(),
        'type' => 'account.updated',
        'payload' => ['type' => 'account.updated', 'data' => ['object' => []]],
        'status' => WebhookStatus::Processed,
    ]);
}

it('reports a healthy installation', function () {
    $this->artisan('cashier-connect:doctor', ['--offline' => true])
        ->expectsOutputToContain('Cashier Connect is ready.')
        ->assertSuccessful();
});

it('reports a missing webhook secret', function () {
    config(['cashier-connect.webhook.secret' => null]);

    $this->artisan('cashier-connect:doctor', ['--offline' => true])
        ->expectsOutputToContain('cashier-connect:webhook')
        ->assertFailed();
});

it('syncs one or all accounts', function () {
    $shop = shop();
    $account = $shop->createConnectAccount();
    $this->stripe->completeOnboarding($account->stripe_account_id);

    $this->artisan('cashier-connect:sync', ['account' => $account->stripe_account_id])->assertSuccessful();
    expect($shop->isConnectReady())->toBeTrue();

    $this->artisan('cashier-connect:sync', ['--all' => true])->assertSuccessful();
    $this->artisan('cashier-connect:sync')->assertExitCode(2);
});

it('prunes old processed events but keeps failed ones', function () {
    $old = storedEvent();
    $failed = storedEvent(['status' => WebhookStatus::Failed]);
    $recent = storedEvent();
    ConnectWebhookEvent::whereKey([$old->id, $failed->id])->update(['created_at' => now()->subDays(40)]);

    $this->artisan('cashier-connect:prune')->assertSuccessful();

    expect(ConnectWebhookEvent::pluck('id')->all())->toEqualCanonicalizing([$failed->id, $recent->id]);
});

it('replays failed events', function () {
    $account = shop()->createConnectAccount();
    $this->stripe->completeOnboarding($account->stripe_account_id);

    $event = storedEvent([
        'status' => WebhookStatus::Failed,
        'payload' => ['type' => 'account.updated', 'account' => $account->stripe_account_id, 'data' => ['object' => []]],
    ]);

    $this->artisan('cashier-connect:replay', ['--failed' => true])->assertSuccessful();

    expect($event->fresh()->status)->toBe(WebhookStatus::Processed)
        ->and($account->fresh()->isReady())->toBeTrue();
});

it('only replays processed events with --force', function () {
    $event = storedEvent();

    $this->artisan('cashier-connect:replay', ['event' => $event->stripe_event_id])
        ->expectsOutputToContain('--force')
        ->assertSuccessful();

    $this->artisan('cashier-connect:replay', ['event' => $event->stripe_event_id, '--force' => true])->assertSuccessful();

    expect($event->fresh()->attempts)->toBe(1);
});
