<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Events;

use Illuminate\Queue\SerializesModels;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;

final class ConnectAccountUpdated
{
    use SerializesModels;

    public function __construct(
        public ConnectedAccount $account,
        /** @var array<string, mixed> Attributes that changed, with their new values. */
        public array $changes,
    ) {}
}
