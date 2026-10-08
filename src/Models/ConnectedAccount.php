<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stripe\Account;
use Stripe\StripeObject;

/**
 * Local cache of a Stripe connected account. Stripe stays the source of truth.
 *
 * @property int $id
 * @property string $connectable_type
 * @property int|string $connectable_id
 * @property string $stripe_account_id
 * @property string|null $type
 * @property string|null $country
 * @property string|null $default_currency
 * @property string|null $email
 * @property bool $charges_enabled
 * @property bool $payouts_enabled
 * @property bool $details_submitted
 * @property array<string, mixed>|null $requirements
 * @property array<string, string>|null $capabilities
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $deauthorized_at
 * @property Carbon|null $synced_at
 * @property-read Model|null $connectable
 */
class ConnectedAccount extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'charges_enabled' => 'boolean',
        'payouts_enabled' => 'boolean',
        'details_submitted' => 'boolean',
        'requirements' => 'array',
        'capabilities' => 'array',
        'metadata' => 'array',
        'deauthorized_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('cashier-connect.tables.accounts', parent::getTable());
    }

    /** @return MorphTo<Model, $this> */
    public function connectable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Charges and payouts are both enabled and the platform still has access.
     */
    public function isReady(): bool
    {
        return $this->charges_enabled && $this->payouts_enabled && ! $this->isDeauthorized();
    }

    /**
     * Stripe has disabled the account or information is past due.
     */
    public function isRestricted(): bool
    {
        return $this->disabledReason() !== null || $this->pastDue() !== [];
    }

    public function isDeauthorized(): bool
    {
        return $this->deauthorized_at !== null;
    }

    public function disabledReason(): ?string
    {
        return $this->requirements['disabled_reason'] ?? null;
    }

    /** @return list<string> */
    public function currentlyDue(): array
    {
        return $this->requirements['currently_due'] ?? [];
    }

    /** @return list<string> */
    public function pastDue(): array
    {
        return $this->requirements['past_due'] ?? [];
    }

    /** @return list<string> */
    public function eventuallyDue(): array
    {
        return $this->requirements['eventually_due'] ?? [];
    }

    /**
     * Fill local attributes from a Stripe Account object (does not save).
     */
    public function fillFromStripe(Account $account): static
    {
        $requirements = self::stripeArray($account->requirements ?? null);

        $capabilities = self::stripeArray($account->capabilities ?? null);

        $this->fill([
            'stripe_account_id' => $account->id,
            'type' => $account->type ?? $this->type,
            'country' => $account->country ?? $this->country,
            'default_currency' => $account->default_currency ?? $this->default_currency,
            'email' => $account->email ?? $this->email,
            'charges_enabled' => (bool) ($account->charges_enabled ?? false),
            'payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
            'details_submitted' => (bool) ($account->details_submitted ?? false),
            'requirements' => array_intersect_key($requirements, array_flip([
                'currently_due', 'eventually_due', 'past_due', 'pending_verification',
                'disabled_reason', 'current_deadline', 'errors',
            ])),
            'capabilities' => $capabilities,
            'synced_at' => Carbon::now(),
        ]);

        return $this;
    }

    /** @return array<string, mixed> */
    private static function stripeArray(mixed $value): array
    {
        return match (true) {
            $value instanceof StripeObject => $value->toArray(),
            is_array($value) => $value,
            default => [],
        };
    }
}
