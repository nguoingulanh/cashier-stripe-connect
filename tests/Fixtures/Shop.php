<?php

namespace Nguoingulanh\CashierConnect\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nguoingulanh\CashierConnect\Concerns\ConnectBillable;

class Shop extends Model
{
    use ConnectBillable;

    protected $guarded = [];
}
