<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Nguoingulanh\CashierConnect\CashierConnect as Manager;
use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Testing\FakeStripeGateway;

/**
 * @method static \Nguoingulanh\CashierConnect\ConnectOwner for(\Illuminate\Database\Eloquent\Model $owner)
 * @method static \Nguoingulanh\CashierConnect\Models\ConnectedAccount|null account(string $stripeAccountId)
 * @method static \Nguoingulanh\CashierConnect\Services\PaymentOptions destination(\Illuminate\Database\Eloquent\Model|\Nguoingulanh\CashierConnect\Models\ConnectedAccount|string $account)
 * @method static \Stripe\StripeClient onBehalfOf(\Illuminate\Database\Eloquent\Model|\Nguoingulanh\CashierConnect\Models\ConnectedAccount|string $account)
 * @method static \Stripe\StripeClient stripe()
 * @method static \Stripe\Refund refund(string $paymentIntent, ?int $amount = null, bool $reverseTransfer = false, bool $refundApplicationFee = false, \Illuminate\Database\Eloquent\Model|\Nguoingulanh\CashierConnect\Models\ConnectedAccount|string|null $onAccount = null, array<string, mixed> $params = [], array<string, mixed> $options = [])
 * @method static Manager handleWebhookUsing(string $type, string|callable $handler)
 * @method static \Nguoingulanh\CashierConnect\Services\AccountService accounts()
 * @method static \Nguoingulanh\CashierConnect\Services\OnboardingService onboarding()
 * @method static \Nguoingulanh\CashierConnect\Services\FundsService funds()
 * @method static StripeGateway gateway()
 *
 * @see Manager
 */
class CashierConnect extends Facade
{
    /**
     * Replace the Stripe gateway with an in-memory fake. No HTTP calls are made.
     */
    public static function fake(): FakeStripeGateway
    {
        $fake = new FakeStripeGateway;

        static::getFacadeApplication()?->instance(StripeGateway::class, $fake);

        return $fake;
    }

    public static function assertAccountCreatedFor(Model $owner): void
    {
        static::fakeGateway()->assertAccountCreatedFor($owner);
    }

    public static function assertTransferred(Model|string $account, ?int $amount = null): void
    {
        static::fakeGateway()->assertTransferred(static::accountId($account), $amount);
    }

    public static function assertPayoutCreated(Model|string $account, ?int $amount = null): void
    {
        static::fakeGateway()->assertPayoutCreated(static::accountId($account), $amount);
    }

    public static function assertRefunded(string $paymentIntent, ?int $amount = null): void
    {
        static::fakeGateway()->assertRefunded($paymentIntent, $amount);
    }

    protected static function fakeGateway(): FakeStripeGateway
    {
        $gateway = static::getFacadeApplication()?->make(StripeGateway::class);

        if (! $gateway instanceof FakeStripeGateway) {
            throw new \LogicException('Call CashierConnect::fake() before using assertions.');
        }

        return $gateway;
    }

    protected static function accountId(Model|string $account): string
    {
        return is_string($account) ? $account : static::resolveAccount($account)->stripe_account_id;
    }

    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
