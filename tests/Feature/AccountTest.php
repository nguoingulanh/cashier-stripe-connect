<?php

use Illuminate\Support\Facades\Event;
use Nguoingulanh\CashierConnect\Events\ConnectAccountCreated;
use Nguoingulanh\CashierConnect\Events\ConnectAccountDeauthorized;
use Nguoingulanh\CashierConnect\Events\ConnectAccountDeleted;
use Nguoingulanh\CashierConnect\Events\ConnectAccountReady;
use Nguoingulanh\CashierConnect\Events\ConnectAccountRestricted;
use Nguoingulanh\CashierConnect\Events\ConnectAccountUpdated;
use Nguoingulanh\CashierConnect\Exceptions\AccountAlreadyExists;
use Nguoingulanh\CashierConnect\Exceptions\AccountNotFound;
use Nguoingulanh\CashierConnect\Exceptions\StripeConnectException;
use Nguoingulanh\CashierConnect\Facades\CashierConnect;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;

beforeEach(function () {
    $this->stripe = fakeStripe();
    Event::fake();
});

it('creates an express account with sensible defaults', function () {
    $shop = shop();

    $account = $shop->createConnectAccount();

    expect($account)->toBeInstanceOf(ConnectedAccount::class)
        ->and($account->stripe_account_id)->toStartWith('acct_')
        ->and($account->type)->toBe('express')
        ->and($account->isReady())->toBeFalse()
        ->and($account->currentlyDue())->toContain('external_account')
        ->and($account->connectable->is($shop))->toBeTrue();

    $this->stripe->assertCalled('createAccount', fn (array $params) => $params['type'] === 'express'
        && $params['email'] === 'owner@acme.test'
        && $params['capabilities'] === ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]]
        && $params['metadata'] === ['connectable_type' => $shop->getMorphClass(), 'connectable_id' => (string) $shop->id]);

    CashierConnect::assertAccountCreatedFor($shop);
    Event::assertDispatched(ConnectAccountCreated::class);
    Event::assertNotDispatched(ConnectAccountReady::class);
});

it('works without the trait through the facade', function () {
    $shop = shop();

    $account = CashierConnect::for($shop)->create(['country' => 'VN']);

    expect(CashierConnect::for($shop)->hasAccount())->toBeTrue()
        ->and(CashierConnect::for($shop)->account()->is($account))->toBeTrue()
        ->and(CashierConnect::account($account->stripe_account_id)->is($account))->toBeTrue();

    $this->stripe->assertCalled('createAccount', fn (array $params) => $params['country'] === 'VN');
});

it('does not request capabilities for standard accounts', function () {
    shop()->createConnectAccount(['type' => 'standard']);

    $this->stripe->assertCalled('createAccount', fn (array $params) => ! isset($params['capabilities']));
});

it('does not force a type when controller properties are used', function () {
    shop()->createConnectAccount(['controller' => ['stripe_dashboard' => ['type' => 'express']]]);

    $this->stripe->assertCalled('createAccount', fn (array $params) => ! isset($params['type']) && ! isset($params['capabilities']));
});

it('keeps user metadata and does not prefill email when disabled', function () {
    config(['cashier-connect.prefill_email' => false]);

    shop()->createConnectAccount(['metadata' => ['plan' => 'pro']]);

    $this->stripe->assertCalled('createAccount', fn (array $params) => ! isset($params['email']) && $params['metadata']['plan'] === 'pro');
});

it('prevents a second account unless multiple accounts are enabled', function () {
    $shop = shop();
    $shop->createConnectAccount();

    expect(fn () => $shop->createConnectAccount())->toThrow(AccountAlreadyExists::class);

    config(['cashier-connect.multiple_accounts' => true]);
    $second = $shop->createConnectAccount();

    expect($shop->connectAccounts()->count())->toBe(2)
        ->and($shop->fresh()->connectAccount->is($second))->toBeTrue();
});

it('throws a helpful error when the owner has no account', function () {
    expect(fn () => shop()->syncConnectAccount())->toThrow(AccountNotFound::class);
});

it('fires ready exactly once when onboarding completes', function () {
    $shop = shop();
    $account = $shop->createConnectAccount();

    $this->stripe->completeOnboarding($account->stripe_account_id);

    $shop->syncConnectAccount();
    $shop->syncConnectAccount();

    expect($shop->isConnectReady())->toBeTrue()
        ->and($shop->connectRequirements()['currently_due'])->toBe([]);

    Event::assertDispatchedTimes(ConnectAccountReady::class, 1);
    // The second sync changes nothing, so only one Updated event is fired.
    Event::assertDispatchedTimes(ConnectAccountUpdated::class, 1);
    Event::assertDispatched(ConnectAccountUpdated::class, fn ($e) => ($e->changes['charges_enabled'] ?? null) === true);
});

it('does not report changes when stripe returns the same data in another key order', function () {
    $shop = shop();
    $account = $shop->createConnectAccount();
    $shop->syncConnectAccount();

    $requirements = $account->fresh()->requirements;
    $this->stripe->setAccount($account->stripe_account_id, ['requirements' => array_reverse($requirements, true)]);
    Event::fake();

    $shop->syncConnectAccount();

    Event::assertNotDispatched(ConnectAccountUpdated::class);
});

it('fires restricted when information is past due', function () {
    $shop = shop();
    $account = $shop->createConnectAccount();

    $this->stripe->restrict($account->stripe_account_id, ['external_account']);
    $synced = $shop->syncConnectAccount();

    expect($synced->isRestricted())->toBeTrue()
        ->and($synced->disabledReason())->toBe('requirements.past_due');

    Event::assertDispatched(ConnectAccountRestricted::class);
});

it('updates the account on stripe and syncs the response', function () {
    $shop = shop();
    $shop->createConnectAccount();

    $account = $shop->updateConnectAccount(['email' => 'new@acme.test']);

    expect($account->email)->toBe('new@acme.test');
});

it('deletes the account on stripe and locally', function () {
    $shop = shop();
    $account = $shop->createConnectAccount();

    $shop->deleteConnectAccount();

    expect($shop->hasConnectAccount())->toBeFalse()
        ->and(ConnectedAccount::withTrashed()->find($account->id)->trashed())->toBeTrue();

    $this->stripe->assertCalled('deleteAccount');
    Event::assertDispatched(ConnectAccountDeleted::class);
});

it('still deletes locally when the account is already gone on stripe', function () {
    $shop = shop();
    $shop->createConnectAccount();
    $this->stripe->failNext('deleteAccount', 'No such account', 'resource_missing');

    $shop->deleteConnectAccount();

    expect($shop->hasConnectAccount())->toBeFalse();
});

it('rethrows other stripe errors on delete', function () {
    $shop = shop();
    $shop->createConnectAccount();
    $this->stripe->failNext('deleteAccount', 'Balance must be zero', 'balance_not_zero');

    expect(fn () => $shop->deleteConnectAccount())->toThrow(StripeConnectException::class);
    expect($shop->hasConnectAccount())->toBeTrue();
});

it('marks deauthorized accounts as not ready', function () {
    $shop = shop();
    $account = $shop->createConnectAccount();
    $this->stripe->completeOnboarding($account->stripe_account_id);
    $shop->syncConnectAccount();

    CashierConnect::accounts()->markDeauthorized($account->fresh());
    CashierConnect::accounts()->markDeauthorized($account->fresh());

    expect($shop->isConnectReady())->toBeFalse();
    Event::assertDispatchedTimes(ConnectAccountDeauthorized::class, 1);
});
