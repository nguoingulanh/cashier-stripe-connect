<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Events;

use Illuminate\Queue\SerializesModels;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Stripe\Transfer;

final class ConnectTransferReversed
{
    use SerializesModels;

    public function __construct(
        public Transfer $transfer,
        public ?ConnectedAccount $account,
    ) {}
}
