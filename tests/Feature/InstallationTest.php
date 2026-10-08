<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nguoingulanh\CashierConnect\CashierConnect;
use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Gateway\CashierStripeGateway;

it('works out of the box', function () {
    expect(Route::has('cashier-connect.webhook'))->toBeTrue()
        ->and(Route::has('cashier-connect.onboarding.return'))->toBeTrue()
        ->and(Schema::hasTable('stripe_connected_accounts'))->toBeTrue()
        ->and(Schema::hasTable('stripe_connect_webhook_events'))->toBeTrue()
        ->and(app(StripeGateway::class))->toBeInstanceOf(CashierStripeGateway::class)
        ->and(app(CashierConnect::class))->toBe(app(CashierConnect::class))
        ->and(app('router')->getMiddleware())->toHaveKey('connect.ready');
});

it('registers the webhook route outside the web middleware group', function () {
    $route = Route::getRoutes()->getByName('cashier-connect.webhook');

    expect($route->gatherMiddleware())->not->toContain('web')
        ->and($route->uri())->toBe('stripe/connect/webhook');
});
