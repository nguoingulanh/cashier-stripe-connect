<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Nguoingulanh\CashierConnect\Webhooks\WebhookProcessor;

final class HandleConnectWebhook implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(WebhookProcessor $processor): void
    {
        $record = WebhookProcessor::model()::query()->find($this->eventId);

        if ($record !== null) {
            $processor->process($record);
        }
    }
}
