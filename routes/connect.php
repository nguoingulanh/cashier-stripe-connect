<?php

use Illuminate\Support\Facades\Route;
use Nguoingulanh\CashierConnect\Http\Controllers\OnboardingController;
use Nguoingulanh\CashierConnect\Http\Controllers\WebhookController;
use Nguoingulanh\CashierConnect\Http\Middleware\VerifyConnectSignature;

// Outside the "web" group: no session, no CSRF verification needed.
Route::post(config('cashier-connect.webhook.path', 'stripe/connect/webhook'), WebhookController::class)
    ->middleware(VerifyConnectSignature::class)
    ->name('cashier-connect.webhook');

Route::middleware('web')
    ->prefix(config('cashier-connect.onboarding.path_prefix', 'stripe/connect'))
    ->group(function () {
        Route::get('accounts/{account}/return', [OnboardingController::class, 'return'])
            ->name('cashier-connect.onboarding.return');

        Route::get('accounts/{account}/refresh', [OnboardingController::class, 'refresh'])
            ->name('cashier-connect.onboarding.refresh');
    });
