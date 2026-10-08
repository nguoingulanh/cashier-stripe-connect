<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Enums;

enum AccountType: string
{
    case Express = 'express';
    case Standard = 'standard';
    case Custom = 'custom';

    /**
     * Whether the platform may request capabilities when creating the account.
     */
    public function requestsCapabilities(): bool
    {
        return $this !== self::Standard;
    }
}
