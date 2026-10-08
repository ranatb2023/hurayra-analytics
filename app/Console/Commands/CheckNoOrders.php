<?php

namespace App\Console\Commands;

use App\Services\NoOrdersAlert;
use Illuminate\Console\Command;

class CheckNoOrders extends Command
{
    protected $signature = 'alerts:no-orders';

    protected $description = 'Alert (email / Slack) when no new order has arrived for ALERT_NO_ORDERS_HOURS during UK trading hours';

    public function handle(NoOrdersAlert $alert): int
    {
        $this->line('No-orders check: '.$alert->check());

        return self::SUCCESS;
    }
}
