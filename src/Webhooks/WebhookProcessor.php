<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Webhooks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nguoingulanh\CashierConnect\CashierConnect;
use Nguoingulanh\CashierConnect\Enums\WebhookStatus;
use Nguoingulanh\CashierConnect\Events\ConnectWebhookHandled;
use Nguoingulanh\CashierConnect\Events\ConnectWebhookReceived;
use Nguoingulanh\CashierConnect\Models\ConnectWebhookEvent;
use Nguoingulanh\CashierConnect\Webhooks\Handlers\DeauthorizeAccount;
use Nguoingulanh\CashierConnect\Webhooks\Handlers\PayoutStatus;
use Nguoingulanh\CashierConnect\Webhooks\Handlers\SyncAccount;
use Stripe\Event;
use Throwable;

/**
 * Stores Connect events once (idempotency) and runs their handlers.
 */
final class WebhookProcessor
{
    /** Minutes after which a "processing" event is considered abandoned. */
    private const STALE_AFTER = 10;

    /** @var array<string, class-string<WebhookHandler>> */
    private const DEFAULT_HANDLERS = [
        'account.updated' => SyncAccount::class,
        'capability.updated' => SyncAccount::class,
        'person.updated' => SyncAccount::class,
        'account.application.deauthorized' => DeauthorizeAccount::class,
        'payout.paid' => PayoutStatus::class,
        'payout.failed' => PayoutStatus::class,
        'payout.canceled' => PayoutStatus::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $events,
        private readonly CashierConnect $manager,
    ) {}

    /**
     * Persist the event unless it was already received.
     */
    public function record(Event $event): ConnectWebhookEvent
    {
        $model = self::model();

        $model::query()->insertOrIgnore([
            'stripe_event_id' => $event->id,
            'type' => $event->type,
            'stripe_account_id' => $event->account ?? null,
            'livemode' => (bool) $event->livemode,
            'payload' => json_encode($event->toArray(), JSON_THROW_ON_ERROR),
            'status' => WebhookStatus::Pending->value,
            'attempts' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $model::query()->where('stripe_event_id', $event->id)->firstOrFail();
    }

    /**
     * Run the handler for a stored event. Returns false when another worker
     * currently owns the event. Rethrows handler failures after recording them.
     */
    public function process(ConnectWebhookEvent $record): bool
    {
        if (! $this->claim($record)) {
            return $record->refresh()->status === WebhookStatus::Processed;
        }

        $payload = $record->payload;

        try {
            $this->events->dispatch(new ConnectWebhookReceived($payload));

            if ($handler = $this->handlerFor($record->type)) {
                $handler($payload);
            }

            $this->events->dispatch(new ConnectWebhookHandled($payload));
        } catch (Throwable $e) {
            $record->forceFill([
                'status' => WebhookStatus::Failed,
                'last_error' => Str::limit($e::class.': '.$e->getMessage(), 2000),
            ])->save();

            throw $e;
        }

        $record->forceFill([
            'status' => WebhookStatus::Processed,
            'processed_at' => Carbon::now(),
            'last_error' => null,
        ])->save();

        return true;
    }

    /**
     * @return (callable(array<string, mixed>): void)|null
     */
    public function handlerFor(string $type): ?callable
    {
        $handler = $this->manager->customWebhookHandlers()[$type] ?? self::DEFAULT_HANDLERS[$type] ?? null;

        if ($handler === null) {
            return null;
        }

        if (is_string($handler) && ! is_callable($handler)) {
            $instance = $this->container->make($handler);

            return $instance instanceof WebhookHandler
                ? $instance->handle(...)
                : $instance(...);
        }

        return $handler(...);
    }

    /**
     * @return class-string<ConnectWebhookEvent>
     */
    public static function model(): string
    {
        return config('cashier-connect.models.webhook_event', ConnectWebhookEvent::class);
    }

    /**
     * Atomically move the event to "processing" so concurrent deliveries
     * (Stripe retries, queue workers) never run the same handler twice.
     */
    private function claim(ConnectWebhookEvent $record): bool
    {
        $claimed = self::model()::query()
            ->whereKey($record->getKey())
            ->where(function (Builder $query) {
                $query->whereIn('status', [WebhookStatus::Pending->value, WebhookStatus::Failed->value])
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', WebhookStatus::Processing->value)
                        ->where('updated_at', '<', Carbon::now()->subMinutes(self::STALE_AFTER)));
            })
            ->update([
                'status' => WebhookStatus::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => Carbon::now(),
            ]);

        if ($claimed === 1) {
            $record->refresh();

            return true;
        }

        return false;
    }
}
