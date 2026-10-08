<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Events;

use Illuminate\Queue\SerializesModels;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Stripe\ApplicationFee;

final class ConnectApplicationFeeRefunded
{
    use SerializesModels;

    public function __construct(
        public ApplicationFee $fee,
        public ?ConnectedAccount $account,
    ) {}
}
