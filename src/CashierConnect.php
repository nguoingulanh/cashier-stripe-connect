<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nguoingulanh\CashierConnect\Contracts\ConnectedStripeClient;
use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Nguoingulanh\CashierConnect\Services\AccountService;
use Nguoingulanh\CashierConnect\Services\FundsService;
use Nguoingulanh\CashierConnect\Services\OnboardingService;
use Nguoingulanh\CashierConnect\Services\PaymentOptions;
use Nguoingulanh\CashierConnect\Webhooks\WebhookHandler;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Entry point of the package, available through the CashierConnect facade.
 */
class CashierConnect
{
    /** @var array<string, class-string<WebhookHandler>|callable> */
    private array $webhookHandlers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Connect operations for an owner model (no trait required).
     */
    public function for(Model $owner): ConnectOwner
    {
        return new ConnectOwner($owner, $this->accounts(), $this->onboarding(), $this->funds());
    }

    /**
     * Find a local connected account by its Stripe id.
     */
    public function account(string $stripeAccountId): ?ConnectedAccount
    {
        return $this->accounts()->findByStripeId($stripeAccountId);
    }

    /**
     * Destination charge options routed to the given account.
     */
    public function destination(Model|ConnectedAccount|string $account): PaymentOptions
    {
        return new PaymentOptions($this->resolveAccount($account));
    }

    /**
     * A Stripe client whose requests run on the connected account (direct charges).
     *
     *     CashierConnect::onBehalfOf($shop)->paymentIntents->create([...]);
     *
     * @return ConnectedStripeClient&StripeClient
     */
    public function onBehalfOf(Model|ConnectedAccount|string $account): ConnectedStripeClient
    {
        /** @var ConnectedStripeClient&StripeClient */
        return $this->gateway()->client($this->resolveAccount($account)->stripe_account_id);
    }

    /**
     * The platform Stripe client used by the package.
     */
    public function stripe(): StripeClient
    {
        /** @var StripeClient */
        return $this->gateway()->client();
    }

    /**
     * Refund a payment; reverse the transfer / application fee for Connect charges.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function refund(
        string $paymentIntent,
        ?int $amount = null,
        bool $reverseTransfer = false,
        bool $refundApplicationFee = false,
        Model|ConnectedAccount|string|null $onAccount = null,
        array $params = [],
        array $options = [],
    ): Refund {
        return $this->funds()->refund(
            $paymentIntent,
            $amount,
            $reverseTransfer,
            $refundApplicationFee,
            $onAccount === null ? null : $this->resolveAccount($onAccount),
            $params,
            $options,
        );
    }

    /**
     * Register or override the handler of a Connect webhook event type.
     *
     * @param  class-string<WebhookHandler>|callable(array<string, mixed>): void  $handler
     */
    public function handleWebhookUsing(string $type, string|callable $handler): static
    {
        $this->webhookHandlers[$type] = $handler;

        return $this;
    }

    /** @return array<string, class-string<WebhookHandler>|callable> */
    public function customWebhookHandlers(): array
    {
        return $this->webhookHandlers;
    }

    public function accounts(): AccountService
    {
        return $this->container->make(AccountService::class);
    }

    public function onboarding(): OnboardingService
    {
        return $this->container->make(OnboardingService::class);
    }

    public function funds(): FundsService
    {
        return $this->container->make(FundsService::class);
    }

    public function gateway(): StripeGateway
    {
        return $this->container->make(StripeGateway::class);
    }

    public function resolveAccount(Model|ConnectedAccount|string $account): ConnectedAccount
    {
        if ($account instanceof ConnectedAccount) {
            return $account;
        }

        if ($account instanceof Model) {
            return $this->accounts()->findForOrFail($account);
        }

        if (! str_starts_with($account, 'acct_')) {
            throw new InvalidArgumentException("[{$account}] is not a Stripe account id.");
        }

        return $this->accounts()->findByStripeIdOrFail($account);
    }
}
