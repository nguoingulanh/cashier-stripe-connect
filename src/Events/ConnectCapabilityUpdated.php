<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Events;

use Illuminate\Queue\SerializesModels;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;

final class ConnectCapabilityUpdated
{
    use SerializesModels;

    public function __construct(
        public ConnectedAccount $account,
        public string $capability,
        public string $status,
    ) {}
}
