<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nguoingulanh\CashierConnect\Enums\WebhookStatus;

/**
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property string|null $stripe_account_id
 * @property bool $livemode
 * @property array<string, mixed> $payload
 * @property WebhookStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ConnectWebhookEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'livemode' => 'boolean',
        'payload' => 'array',
        'status' => WebhookStatus::class,
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('cashier-connect.tables.events', parent::getTable());
    }
}
