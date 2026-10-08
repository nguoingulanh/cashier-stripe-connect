<?php

use Illuminate\Support\Facades\Route;
use Nguoingulanh\CashierConnect\Tests\Fixtures\User;

beforeEach(function () {
    $this->stripe = fakeStripe();
    $this->user = User::create(['name' => 'Seller', 'email' => 'seller@test.dev']);

    Route::middleware(['web', 'connect.ready'])->get('/sell', fn () => 'ok');
    Route::middleware(['web', 'connect.ready:onboard'])->get('/sell-onboard', fn () => 'ok');
});

it('blocks guests', function () {
    $this->get('/sell')->assertForbidden();
});

it('blocks users without a ready account', function () {
    $this->actingAs($this->user)->get('/sell')->assertForbidden();
});

it('redirects to onboarding when asked to', function () {
    $response = $this->actingAs($this->user)->get('/sell-onboard');

    expect($response->headers->get('Location'))->toStartWith('https://connect.stripe.test/setup/account_onboarding/')
        ->and($this->user->hasConnectAccount())->toBeTrue();
});

it('returns 403 for json requests even in onboard mode', function () {
    $this->actingAs($this->user)->getJson('/sell-onboard')->assertForbidden();
});

it('lets ready users through', function () {
    $account = $this->user->createConnectAccount();
    $this->stripe->completeOnboarding($account->stripe_account_id);
    $this->user->syncConnectAccount();

    $this->actingAs($this->user)->get('/sell')->assertOk()->assertSee('ok');
});
