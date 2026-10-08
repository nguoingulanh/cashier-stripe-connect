<?php

namespace Nguoingulanh\CashierConnect\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Nguoingulanh\CashierConnect\Concerns\ConnectBillable;

class User extends Authenticatable
{
    use ConnectBillable;

    protected $guarded = [];
}
