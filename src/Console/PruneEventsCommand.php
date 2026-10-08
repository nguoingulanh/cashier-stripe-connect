<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Nguoingulanh\CashierConnect\Enums\WebhookStatus;
use Nguoingulanh\CashierConnect\Webhooks\WebhookProcessor;

final class PruneEventsCommand extends Command
{
    protected $signature = 'cashier-connect:prune
        {--days= : Delete events older than this many days}
        {--include-failed : Also delete failed events}';

    protected $description = 'Delete old Connect webhook events';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('cashier-connect.webhook.prune_after_days', 30));

        $query = WebhookProcessor::model()::query()->where('created_at', '<', Carbon::now()->subDays($days));

        if (! $this->option('include-failed')) {
            $query->where('status', WebhookStatus::Processed->value);
        }

        $deleted = $query->delete();

        $this->components->info("Deleted {$deleted} webhook event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
