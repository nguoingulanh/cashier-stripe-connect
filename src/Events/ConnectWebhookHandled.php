<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Events;

use Illuminate\Queue\SerializesModels;

final class ConnectWebhookHandled
{
    use SerializesModels;

    public function __construct(
        /** @var array<string, mixed> */
        public array $payload,
    ) {}
}
