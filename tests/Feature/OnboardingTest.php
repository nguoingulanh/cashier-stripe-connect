<?php

use Illuminate\Support\Facades\URL;
use Nguoingulanh\CashierConnect\Services\OnboardingService;

beforeEach(function () {
    $this->stripe = fakeStripe();
});

it('creates the account on demand and returns a stripe onboarding link', function () {
    $shop = shop();

    $url = $shop->connectOnboardingUrl('https://app.test/dashboard');

    expect($url)->toStartWith('https://connect.stripe.test/setup/account_onboarding/')
        ->and($shop->hasConnectAccount())->toBeTrue();

    $this->stripe->assertCalled('createAccountLink', function (array $params) use ($shop) {
        return $params['account'] === $shop->connectAccountId()
            && $params['type'] === 'account_onboarding'
            && $params['collection_options'] === ['fields' => 'currently_due']
            && str_contains($params['return_url'], '/stripe/connect/accounts/'.$shop->connectAccountId().'/return')
            && str_contains($params['return_url'], 'signature=')
            && str_contains($params['refresh_url'], '/refresh');
    });
});

it('reuses the existing account', function () {
    $shop = shop();
    $shop->connectOnboardingUrl();
    $shop->connectOnboardingUrl();

    expect($this->stripe->calls('createAccount'))->toHaveCount(1);
});

it('syncs and redirects back after onboarding', function () {
    $shop = shop();
    $account = $shop->createConnectAccount();
    $this->stripe->completeOnboarding($account->stripe_account_id);

    $url = app(OnboardingService::class)->returnUrl($account, redirectTo: 'https://app.test/dashboard');

    $this->get($url)->assertRedirect('https://app.test/dashboard');

    expect($shop->isConnectReady())->toBeTrue();
});

it('redirects home without syncing when the signature is invalid', function () {
    $account = shop()->createConnectAccount();

    $this->get("/stripe/connect/accounts/{$account->stripe_account_id}/return?redirect=https://evil.test")
        ->assertRedirect(url('/'));

    $this->stripe->assertNotCalled('retrieveAccount');
});

it('still redirects when syncing fails', function () {
    $account = shop()->createConnectAccount();
    $this->stripe->failNext('retrieveAccount');

    $this->get(app(OnboardingService::class)->returnUrl($account))->assertRedirect(url('/'));
});

it('issues a fresh link when the previous one expired', function () {
    $account = shop()->createConnectAccount();

    $refresh = app(OnboardingService::class)->refreshUrl($account, 'account_update', 'https://app.test/settings');

    $response = $this->get($refresh);

    expect($response->headers->get('Location'))->toStartWith('https://connect.stripe.test/setup/account_update/');
    $this->stripe->assertCalled('createAccountLink', fn (array $p) => $p['type'] === 'account_update' && str_contains($p['return_url'], urlencode('https://app.test/settings')));
});

it('rejects expired refresh links', function () {
    $account = shop()->createConnectAccount();

    $url = URL::temporarySignedRoute(OnboardingService::REFRESH_ROUTE, now()->subMinute(), ['account' => $account->stripe_account_id]);

    $this->get($url)->assertRedirect(url('/'));
    $this->stripe->assertNotCalled('createAccountLink');
});

it('returns express login links and the stripe dashboard for standard accounts', function () {
    $express = shop();
    $express->createConnectAccount();

    $standard = shop();
    $standard->createConnectAccount(['type' => 'standard']);

    expect($express->connectDashboardUrl())->toStartWith('https://connect.stripe.test/express/')
        ->and($standard->connectDashboardUrl())->toBe('https://dashboard.stripe.com/');
});

it('creates account sessions for embedded components', function () {
    $session = shop()->connectAccountSession(['payments', 'payouts' => ['features' => ['instant_payouts' => true]]]);

    expect($session->client_secret)->toStartWith('accs_secret_');
    $this->stripe->assertCalled('createAccountSession', fn (array $p) => $p['components'] === [
        'payments' => ['enabled' => true],
        'payouts' => ['features' => ['instant_payouts' => true], 'enabled' => true],
    ]);
});
