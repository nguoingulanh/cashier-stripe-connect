<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Events;

use Illuminate\Queue\SerializesModels;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Stripe\Payout;

final class ConnectPayoutFailed
{
    use SerializesModels;

    public function __construct(
        public ConnectedAccount $account,
        public Payout $payout,
    ) {}
}
