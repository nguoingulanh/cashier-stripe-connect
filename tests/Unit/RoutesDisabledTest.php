<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Nguoingulanh\CashierConnect\Services\OnboardingService;

it('falls back to the configured return url when routes are disabled', function () {
    fakeStripe();
    config(['cashier-connect.onboarding.return_url' => '/seller']);
    $account = shop()->createConnectAccount();

    // Simulate an application that disabled the package routes.
    $routes = new RouteCollection;
    Route::setRoutes($routes);

    $service = app(OnboardingService::class);

    expect($service->returnUrl($account))->toBe(url('/seller'))
        ->and($service->refreshUrl($account, redirectTo: 'https://app.test/x'))->toBe('https://app.test/x');
});
