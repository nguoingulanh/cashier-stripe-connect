<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Stripe\AccountSession;

final class OnboardingService
{
    public const RETURN_ROUTE = 'cashier-connect.onboarding.return';

    public const REFRESH_ROUTE = 'cashier-connect.onboarding.refresh';

    public function __construct(private readonly StripeGateway $gateway) {}

    /**
     * A Stripe hosted onboarding URL. Links are single-use and short-lived,
     * so generate one right before redirecting the user.
     *
     * @param  string|null  $redirectTo  Where to send the user after Stripe (defaults to config).
     * @param  array<string, mixed>  $params  Extra AccountLink parameters.
     */
    public function onboardingUrl(ConnectedAccount $account, ?string $redirectTo = null, array $params = []): string
    {
        return $this->accountLink($account, 'account_onboarding', $redirectTo, $params + [
            'collection_options' => ['fields' => config('cashier-connect.onboarding.collect', 'currently_due')],
        ]);
    }

    /**
     * A URL where the user can update their account details (Express / Custom).
     *
     * @param  array<string, mixed>  $params
     */
    public function updateUrl(ConnectedAccount $account, ?string $redirectTo = null, array $params = []): string
    {
        return $this->accountLink($account, 'account_update', $redirectTo, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function accountLink(ConnectedAccount $account, string $type, ?string $redirectTo = null, array $params = []): string
    {
        $link = $this->gateway->createAccountLink(array_merge([
            'account' => $account->stripe_account_id,
            'type' => $type,
            'return_url' => $this->returnUrl($account, $type, $redirectTo),
            'refresh_url' => $this->refreshUrl($account, $type, $redirectTo),
        ], $params));

        return $link->url;
    }

    /**
     * Express dashboard login link. Standard accounts use the regular Stripe dashboard.
     */
    public function dashboardUrl(ConnectedAccount $account): string
    {
        if ($account->type === 'standard') {
            return 'https://dashboard.stripe.com/';
        }

        return $this->gateway->createLoginLink($account->stripe_account_id)->url;
    }

    /**
     * An AccountSession for Stripe Connect embedded components.
     *
     * @param  array<int|string, mixed>  $components  ['payments', 'payouts'] or full component config.
     */
    public function accountSession(ConnectedAccount $account, array $components = ['account_onboarding']): AccountSession
    {
        $normalized = [];

        foreach ($components as $key => $value) {
            if (is_int($key)) {
                $normalized[$value] = ['enabled' => true];
            } else {
                $normalized[$key] = is_array($value) ? $value + ['enabled' => true] : ['enabled' => (bool) $value];
            }
        }

        return $this->gateway->createAccountSession([
            'account' => $account->stripe_account_id,
            'components' => $normalized,
        ]);
    }

    public function returnUrl(ConnectedAccount $account, string $type = 'account_onboarding', ?string $redirectTo = null): string
    {
        return $this->signedUrl(self::RETURN_ROUTE, $account, $type, $redirectTo);
    }

    public function refreshUrl(ConnectedAccount $account, string $type = 'account_onboarding', ?string $redirectTo = null): string
    {
        return $this->signedUrl(self::REFRESH_ROUTE, $account, $type, $redirectTo);
    }

    private function signedUrl(string $route, ConnectedAccount $account, string $type, ?string $redirectTo): string
    {
        // Routes disabled by the application: fall back to the configured URL.
        if (! Route::has($route)) {
            return $redirectTo ?? URL::to((string) config('cashier-connect.onboarding.return_url', '/'));
        }

        return URL::temporarySignedRoute(
            $route,
            Carbon::now()->addMinutes((int) config('cashier-connect.onboarding.link_ttl', 1440)),
            array_filter([
                'account' => $account->stripe_account_id,
                'type' => $type,
                'redirect' => $redirectTo,
            ]),
        );
    }
}
