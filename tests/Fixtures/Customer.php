<?php

namespace Nguoingulanh\CashierConnect\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Cashier\Billable;

class Customer extends Authenticatable
{
    use Billable;

    protected $table = 'users';

    protected $guarded = [];
}
