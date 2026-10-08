# Cashier Connect

[![Tests](https://github.com/nguoingulanh/cashier-stripe-connect/actions/workflows/tests.yml/badge.svg)](https://github.com/nguoingulanh/cashier-stripe-connect/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/nguoingulanh/cashier-connect.svg)](https://packagist.org/packages/nguoingulanh/cashier-connect)
[![Total Downloads](https://img.shields.io/packagist/dt/nguoingulanh/cashier-connect.svg)](https://packagist.org/packages/nguoingulanh/cashier-connect)
[![License](https://img.shields.io/packagist/l/nguoingulanh/cashier-connect.svg)](LICENSE)

**Stripe Connect for [Laravel Cashier (Stripe)](https://laravel.com/docs/billing).**

Cashier bills your *customers*. Cashier Connect pays your *sellers*: onboarding, connected account status, destination / direct charges, transfers, payouts, refunds and Connect webhooks, without touching Cashier itself.

- [Requirements](#requirements)
- [Installation](#installation)
- [Connected accounts](#connected-accounts)
- [Onboarding](#onboarding)
- [Taking payments](#taking-payments)
- [Transfers, balance and payouts](#transfers-balance-and-payouts)
- [Refunds](#refunds)
- [Webhooks](#webhooks)
- [Events](#events)
- [Middleware](#middleware)
- [Artisan commands](#artisan-commands)
- [Testing your application](#testing-your-application)
- [Configuration](#configuration)

## Requirements

| Package | PHP | Laravel | Cashier |
|---------|-----|---------|---------|
| 1.x     | 8.2+ | 11, 12, 13 | 15, 16 |

## Installation

```bash
composer require nguoingulanh/cashier-connect
php artisan migrate
php artisan cashier-connect:webhook   # prints STRIPE_CONNECT_WEBHOOK_SECRET
```

Add the printed secret to `.env`:

```dotenv
STRIPE_CONNECT_WEBHOOK_SECRET=whsec_...
```

That's it. The package reuses Cashier's `STRIPE_KEY` / `STRIPE_SECRET`, registers its routes, migrations and commands automatically, and needs no change to your `users` table. Run `php artisan cashier-connect:doctor` at any time to check the setup.

## Connected accounts

Any Eloquent model can own a connected account: a `User`, a `Shop`, a `Vendor`... Use the facade, or add the optional `ConnectBillable` trait for shorter calls:

```php
use Nguoingulanh\CashierConnect\Concerns\ConnectBillable;

class Shop extends Model
{
    use ConnectBillable;
}
```

```php
use Nguoingulanh\CashierConnect\Facades\CashierConnect;

// With the trait                       // Without the trait
$shop->createConnectAccount();          CashierConnect::for($shop)->create();
$shop->hasConnectAccount();             CashierConnect::for($shop)->hasAccount();
$shop->connectAccount;                  CashierConnect::for($shop)->account();   // local model
$shop->asStripeConnectAccount();        CashierConnect::for($shop)->asStripeAccount(); // \Stripe\Account
$shop->updateConnectAccount([...]);
$shop->syncConnectAccount();
$shop->deleteConnectAccount();
$shop->isConnectReady();                // charges and payouts enabled
$shop->connectRequirements();           // currently_due, past_due, disabled_reason...
```

Accounts are **Express** by default. Pass any [Stripe account parameter](https://docs.stripe.com/api/accounts/create) to change that:

```php
$shop->createConnectAccount(['type' => 'standard', 'country' => 'FR']);

$shop->createConnectAccount([
    'controller' => ['stripe_dashboard' => ['type' => 'express']],
]);
```

When creating Express or Custom accounts, the package requests the `card_payments` and `transfers` capabilities, pre-fills the owner's `email` and stores the owner in the account metadata. By default a model owns a single account. Set `multiple_accounts` to `true` in the config to allow more than one.

## Onboarding

Redirect the seller to Stripe-hosted onboarding. The connected account is created first if it does not exist yet:

```php
Route::get('/seller/onboarding', function (Request $request) {
    return redirect($request->user()->connectOnboardingUrl(route('seller.dashboard')));
});
```

When the seller comes back, the package syncs the account status and redirects to the URL you passed, or to `onboarding.return_url` if you passed none. If the Stripe link expired, a new one is issued automatically. The return and refresh URLs are signed, so they work without a session.

```php
$shop->connectUpdateUrl();          // let the seller update their details
$shop->connectDashboardUrl();       // Express dashboard login link
$shop->connectAccountSession(['payments', 'payouts']); // client_secret for embedded components
```

## Taking payments

### Destination charges

The customer pays the platform, and the funds are transferred to the seller. Build the options with `destination()` and pass them to Cashier:

```php
// One-off charge: $100, the platform keeps $10
$user->charge(10000, $paymentMethod, CashierConnect::destination($shop)->fee(1000)->toArray());

// Percentage fee needs the amount
$user->charge(10000, $paymentMethod, CashierConnect::destination($shop)->feePercent(10)->toArray(10000));

// Checkout
$user->checkout(['price_123' => 1], CashierConnect::destination($shop)->fee(500)->forCheckout());

// Subscriptions: a percentage of every invoice
$user->newSubscription('default', 'price_monthly')
    ->create($paymentMethod, [], CashierConnect::destination($shop)->feePercent(10)->forSubscription());

// Subscription Checkout
$user->newSubscription('default', 'price_monthly')
    ->checkout(CashierConnect::destination($shop)->feePercent(10)->forSubscriptionCheckout());
```

Other builder methods: `->onBehalfOf()` (the seller becomes the merchant of record), `->transferAmount(9000)`, `->transferGroup('ORDER_1')` and `->with([...])` to add any raw parameter.

### Direct charges

The payment is created on the connected account itself. `onBehalfOf()` returns a Stripe client that sends every request on that account:

```php
CashierConnect::onBehalfOf($shop)->paymentIntents->create([
    'amount' => 10000,
    'currency' => 'usd',
    'application_fee_amount' => 1000,
]);
```

## Transfers, balance and payouts

For separate charges and transfers:

```php
$transfer = $shop->transferToConnect(5000, 'usd', ['transfer_group' => 'ORDER_1']);
$shop->reverseConnectTransfer($transfer->id, 2000);
$shop->connectTransfers();

$shop->connectBalance();        // \Stripe\Balance of the connected account
$shop->connectPayout(5000);     // pay out to the seller's bank account
$shop->connectPayouts();
```

All money-moving methods accept Stripe request options, e.g. `['idempotency_key' => "order-{$order->id}"]`.

## Refunds

```php
// Destination charge: take the money back from the seller and refund your fee
CashierConnect::refund('pi_123', reverseTransfer: true, refundApplicationFee: true);

// Partial refund of a direct charge (made on the connected account)
CashierConnect::refund('pi_123', amount: 500, onAccount: $shop);
```

## Webhooks

Stripe sends connected account events to a dedicated **Connect** endpoint. `php artisan cashier-connect:webhook` creates it at `/stripe/connect/webhook` with these events:

`account.updated`, `account.application.deauthorized`, `capability.updated`, `person.updated`, `payout.paid`, `payout.failed`, `payout.canceled`

How the endpoint handles events:

- Signatures are verified. Separate several secrets with commas (`whsec_old,whsec_new`) to rotate a secret without downtime.
- Each event is stored once and its handler runs once, even when Stripe delivers it again.
- The account is **re-fetched from Stripe** instead of trusting the payload, so events that arrive out of order still leave the correct state.
- If a handler fails, the endpoint answers `500` and Stripe retries. Run `php artisan cashier-connect:replay --failed` to retry stored failures manually.
- Live endpoints ignore test-mode events from connected accounts.

To process events in the background, set a queue:

```dotenv
CASHIER_CONNECT_QUEUE=default
```

Add or override handlers in a service provider:

```php
CashierConnect::handleWebhookUsing('balance.available', function (array $payload) {
    // ...
});
CashierConnect::handleWebhookUsing('account.updated', MyAccountHandler::class); // implements WebhookHandler
```

**Platform events.** Transfers and application fees happen on *your* account, so Stripe sends them to Cashier's regular webhook. If you enable `transfer.created`, `transfer.reversed` and `application_fee.refunded` on that endpoint, the package turns them into Connect events as well.

## Events

| Event | When |
|-------|------|
| `ConnectAccountCreated` | An account was created |
| `ConnectAccountUpdated` | Synced attributes changed (`$event->changes`) |
| `ConnectAccountReady` | Charges and payouts became enabled. Fired once per transition |
| `ConnectAccountRestricted` | Stripe disabled the account or information is past due |
| `ConnectAccountDeauthorized` | The seller disconnected your platform |
| `ConnectAccountDeleted` | The account was deleted |
| `ConnectCapabilityUpdated` | A capability changed status |
| `ConnectPayoutPaid` / `Failed` / `Canceled` | Payout status on the connected account |
| `ConnectTransferCreated` / `Reversed` | Platform transfer events |
| `ConnectApplicationFeeRefunded` | An application fee was refunded |
| `ConnectWebhookReceived` / `Handled` | Every Connect webhook |

All events live in `Nguoingulanh\CashierConnect\Events` and carry the local `ConnectedAccount` (`$event->account->connectable` is your model).

```php
Event::listen(ConnectAccountReady::class, function ($event) {
    $event->account->connectable->notify(new PayoutsEnabled);
});
```

## Middleware

```php
Route::middleware(['auth', 'connect.ready'])->group(...);          // 403 until onboarding is complete
Route::middleware(['auth', 'connect.ready:onboard'])->group(...);  // redirect to Stripe onboarding instead
```

## Artisan commands

| Command | Description |
|---------|-------------|
| `cashier-connect:install` | Optional guided setup |
| `cashier-connect:webhook` | Create the Connect webhook endpoint and print its secret |
| `cashier-connect:doctor` | Check keys, secret, tables, routes and Stripe connectivity |
| `cashier-connect:sync {account?} {--all}` | Pull account status from Stripe |
| `cashier-connect:replay {event?} {--failed} {--force}` | Run stored webhook events again |
| `cashier-connect:prune {--days=}` | Delete old processed webhook events (scheduled daily) |

## Testing your application

`CashierConnect::fake()` swaps Stripe for an in-memory gateway, so your tests make no HTTP calls:

```php
$stripe = CashierConnect::fake();

$this->actingAs($seller)->get('/seller/onboarding')->assertRedirect();

$stripe->completeOnboarding($seller->connectAccountId());
$seller->syncConnectAccount();

$this->actingAs($buyer)->post("/orders/{$order->id}/pay");

CashierConnect::assertAccountCreatedFor($seller);
CashierConnect::assertTransferred($seller, 9000);
CashierConnect::assertPayoutCreated($seller, 9000);
CashierConnect::assertRefunded('pi_123');
```

Other helpers: `restrict()`, `setAccount()`, `failNext('createTransfer')`, `assertCalled()`, `assertNotCalled()` and `calls()`.

## Configuration

Everything works with the defaults. To customise, publish the config:

```bash
php artisan vendor:publish --tag=cashier-connect-config
```

| Key / env | Default | |
|-----------|---------|---|
| `default_type` / `CASHIER_CONNECT_TYPE` | `express` | Account type when none is given |
| `default_country` / `CASHIER_CONNECT_COUNTRY` | `null` | Default account country |
| `capabilities` | `card_payments`, `transfers` | Requested for Express / Custom |
| `multiple_accounts` | `false` | Allow several accounts per model |
| `webhook.secret` / `STRIPE_CONNECT_WEBHOOK_SECRET` | | Comma-separate for rotation |
| `webhook.queue` / `CASHIER_CONNECT_QUEUE` | `null` (sync) | Queue for webhook processing |
| `onboarding.return_url` / `CASHIER_CONNECT_RETURN_URL` | `/` | Default page after onboarding |
| `onboarding.link_ttl` | `1440` | Minutes the signed return / refresh URLs stay valid |
| `routes` | `true` | Set `false` to register routes yourself |
| `models.*`, `tables.*` | | Swap the models or table names |

## License

The MIT License (MIT). See [LICENSE](LICENSE).
