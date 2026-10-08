<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Nguoingulanh\CashierConnect\Services\AccountService;
use Nguoingulanh\CashierConnect\Services\OnboardingService;
use Throwable;

/**
 * Landing routes for Stripe hosted onboarding. URLs are signed, so they work
 * without a session; an invalid or expired signature just sends the user home.
 */
final class OnboardingController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly OnboardingService $onboarding,
    ) {}

    /**
     * The user finished (or left) Stripe onboarding.
     */
    public function return(Request $request, string $account): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->home();
        }

        if ($connected = $this->accounts->findByStripeId($account)) {
            try {
                $this->accounts->sync($connected);
            } catch (Throwable $e) {
                // The webhook will catch up; never block the user's redirect.
                Log::channel(config('cashier-connect.log_channel'))->warning('Cashier Connect: sync after onboarding failed.', [
                    'account' => $account,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->redirectTo($request);
    }

    /**
     * The account link expired or was already used: issue a fresh one.
     */
    public function refresh(Request $request, string $account): RedirectResponse
    {
        if (! $request->hasValidSignature() || ! $connected = $this->accounts->findByStripeId($account)) {
            return $this->home();
        }

        $type = $request->query('type') === 'account_update' ? 'account_update' : 'account_onboarding';
        $redirect = $request->query('redirect');
        $redirect = is_string($redirect) ? $redirect : null;

        $url = $type === 'account_update'
            ? $this->onboarding->updateUrl($connected, $redirect)
            : $this->onboarding->onboardingUrl($connected, $redirect);

        return new RedirectResponse($url);
    }

    private function redirectTo(Request $request): RedirectResponse
    {
        $redirect = $request->query('redirect');

        return is_string($redirect) && $redirect !== '' ? new RedirectResponse($redirect) : $this->home();
    }

    private function home(): RedirectResponse
    {
        return new RedirectResponse(URL::to((string) config('cashier-connect.onboarding.return_url', '/')));
    }
}
