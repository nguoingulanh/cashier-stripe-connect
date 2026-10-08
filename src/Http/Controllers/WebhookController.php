<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Http\Controllers;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nguoingulanh\CashierConnect\Enums\WebhookStatus;
use Nguoingulanh\CashierConnect\Http\Middleware\VerifyConnectSignature;
use Nguoingulanh\CashierConnect\Webhooks\Jobs\HandleConnectWebhook;
use Nguoingulanh\CashierConnect\Webhooks\WebhookProcessor;
use Stripe\Event;
use Throwable;

final class WebhookController
{
    public function __invoke(Request $request, WebhookProcessor $processor, Bus $bus): Response
    {
        /** @var Event $event */
        $event = $request->attributes->get(VerifyConnectSignature::EVENT_ATTRIBUTE);

        // Live Connect endpoints also receive test-mode events from connected accounts.
        if ((bool) $event->livemode !== $this->platformIsLive()) {
            return new Response('Webhook ignored (mode mismatch).', 200);
        }

        $record = $processor->record($event);

        if ($record->status === WebhookStatus::Processed) {
            return new Response('Webhook already handled.', 200);
        }

        if ($queue = config('cashier-connect.webhook.queue')) {
            $bus->dispatch(
                (new HandleConnectWebhook($record->getKey()))
                    ->onConnection(config('cashier-connect.webhook.queue_connection'))
                    ->onQueue($queue)
            );

            return new Response('Webhook queued.', 200);
        }

        try {
            $processor->process($record);
        } catch (Throwable $e) {
            report($e);

            // Non-2xx makes Stripe retry later.
            return new Response('Webhook handling failed.', 500);
        }

        return new Response('Webhook handled.', 200);
    }

    private function platformIsLive(): bool
    {
        $secret = (string) config('cashier.secret');

        return str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'rk_live_');
    }
}
