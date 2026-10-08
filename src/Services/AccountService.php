<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Enums\AccountType;
use Nguoingulanh\CashierConnect\Events\ConnectAccountCreated;
use Nguoingulanh\CashierConnect\Events\ConnectAccountDeauthorized;
use Nguoingulanh\CashierConnect\Events\ConnectAccountDeleted;
use Nguoingulanh\CashierConnect\Events\ConnectAccountReady;
use Nguoingulanh\CashierConnect\Events\ConnectAccountRestricted;
use Nguoingulanh\CashierConnect\Events\ConnectAccountUpdated;
use Nguoingulanh\CashierConnect\Exceptions\AccountAlreadyExists;
use Nguoingulanh\CashierConnect\Exceptions\AccountNotFound;
use Nguoingulanh\CashierConnect\Exceptions\StripeConnectException;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Stripe\Account;

final class AccountService
{
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Create a Stripe connected account owned by the given model.
     *
     * @param  array<string, mixed>  $params  Stripe "create account" parameters.
     * @param  array<string, mixed>  $options  Stripe request options (e.g. idempotency_key).
     */
    public function create(Model $owner, array $params = [], array $options = []): ConnectedAccount
    {
        $lock = Cache::lock($this->lockKey($owner), 30);

        return $lock->block(10, function () use ($owner, $params, $options) {
            if (! config('cashier-connect.multiple_accounts') && $existing = $this->findFor($owner)) {
                throw AccountAlreadyExists::forOwner($owner, $existing->stripe_account_id);
            }

            $stripeAccount = $this->gateway->createAccount($this->buildCreateParams($owner, $params), $options);

            /** @var ConnectedAccount $account */
            $account = new (self::model());
            $account->connectable()->associate($owner);
            $account->fillFromStripe($stripeAccount);
            $account->metadata = $params['metadata'] ?? null;
            $account->save();

            $this->events->dispatch(new ConnectAccountCreated($account));

            if ($account->isReady()) {
                $this->events->dispatch(new ConnectAccountReady($account));
            }

            return $account;
        });
    }

    /**
     * The owner's connected account (the most recent one when multiple are allowed).
     */
    public function findFor(Model $owner): ?ConnectedAccount
    {
        return self::model()::query()
            ->where('connectable_type', $owner->getMorphClass())
            ->where('connectable_id', $owner->getKey())
            ->latest('id')
            ->first();
    }

    public function findForOrFail(Model $owner): ConnectedAccount
    {
        return $this->findFor($owner) ?? throw AccountNotFound::forOwner($owner);
    }

    public function findByStripeId(string $accountId): ?ConnectedAccount
    {
        return self::model()::query()->where('stripe_account_id', $accountId)->first();
    }

    public function findByStripeIdOrFail(string $accountId): ConnectedAccount
    {
        return $this->findByStripeId($accountId) ?? throw AccountNotFound::forStripeId($accountId);
    }

    public function retrieve(ConnectedAccount $account): Account
    {
        return $this->gateway->retrieveAccount($account->stripe_account_id);
    }

    /**
     * Pull the latest state from Stripe (or use the given object) and fire
     * Updated / Ready / Restricted events when the state changes.
     */
    public function sync(ConnectedAccount $account, ?Account $stripeAccount = null): ConnectedAccount
    {
        $stripeAccount ??= $this->gateway->retrieveAccount($account->stripe_account_id);

        $wasReady = $account->isReady();
        $wasRestricted = $account->isRestricted();

        $account->fillFromStripe($stripeAccount);

        $changes = array_keys(Arr::except($account->getDirty(), ['synced_at', 'updated_at']));

        $account->save();

        if ($changes !== []) {
            $this->events->dispatch(new ConnectAccountUpdated(
                $account,
                collect($changes)->mapWithKeys(fn (string $key) => [$key => $account->getAttribute($key)])->all(),
            ));
        }

        if (! $wasReady && $account->isReady()) {
            $this->events->dispatch(new ConnectAccountReady($account));
        }

        if (! $wasRestricted && $account->isRestricted()) {
            $this->events->dispatch(new ConnectAccountRestricted($account));
        }

        return $account;
    }

    /** @param  array<string, mixed>  $params */
    public function update(ConnectedAccount $account, array $params): ConnectedAccount
    {
        return $this->sync($account, $this->gateway->updateAccount($account->stripe_account_id, $params));
    }

    /**
     * Delete the account on Stripe and soft-delete the local record.
     */
    public function delete(ConnectedAccount $account): void
    {
        try {
            $this->gateway->deleteAccount($account->stripe_account_id);
        } catch (StripeConnectException $e) {
            // Already gone on Stripe: still remove it locally.
            if ($e->stripeCode !== 'resource_missing' && $e->stripeCode !== 'account_invalid') {
                throw $e;
            }
        }

        $account->delete();

        $this->events->dispatch(new ConnectAccountDeleted($account));
    }

    public function markDeauthorized(ConnectedAccount $account): ConnectedAccount
    {
        if ($account->isDeauthorized()) {
            return $account;
        }

        $account->forceFill([
            'deauthorized_at' => Carbon::now(),
            'charges_enabled' => false,
            'payouts_enabled' => false,
        ])->save();

        $this->events->dispatch(new ConnectAccountDeauthorized($account));

        return $account;
    }

    /**
     * @return class-string<ConnectedAccount>
     */
    public static function model(): string
    {
        return config('cashier-connect.models.account', ConnectedAccount::class);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildCreateParams(Model $owner, array $params): array
    {
        // Accounts may be described with "controller" properties instead of a type.
        if (! isset($params['type']) && ! isset($params['controller'])) {
            $params['type'] = config('cashier-connect.default_type', 'express');
        }

        if (! isset($params['country']) && $country = config('cashier-connect.default_country')) {
            $params['country'] = $country;
        }

        if (! isset($params['email']) && config('cashier-connect.prefill_email') && is_string($email = $owner->getAttribute('email'))) {
            $params['email'] = $email;
        }

        $type = AccountType::tryFrom((string) ($params['type'] ?? ''));

        if (! isset($params['capabilities']) && $type?->requestsCapabilities()) {
            /** @var list<string> $capabilities */
            $capabilities = config('cashier-connect.capabilities', []);

            $params['capabilities'] = array_fill_keys($capabilities, ['requested' => true]);
        }

        $params['metadata'] = array_merge($params['metadata'] ?? [], [
            'connectable_type' => $owner->getMorphClass(),
            'connectable_id' => (string) $owner->getKey(),
        ]);

        if (empty($params['capabilities'])) {
            unset($params['capabilities']);
        }

        return $params;
    }

    private function lockKey(Model $owner): string
    {
        return 'cashier-connect:create:'.$owner->getMorphClass().':'.$owner->getKey();
    }
}
