<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Console;

use Illuminate\Console\Command;
use Nguoingulanh\CashierConnect\Enums\WebhookStatus;
use Nguoingulanh\CashierConnect\Webhooks\WebhookProcessor;
use Throwable;

final class ReplayEventCommand extends Command
{
    protected $signature = 'cashier-connect:replay
        {event? : The Stripe event id (evt_...)}
        {--failed : Replay every failed event}
        {--force : Replay even if the event was already processed}';

    protected $description = 'Run the handler of stored Connect webhook events again';

    public function handle(WebhookProcessor $processor): int
    {
        $query = WebhookProcessor::model()::query();

        if ($id = $this->argument('event')) {
            $query->where('stripe_event_id', $id);
        } elseif ($this->option('failed')) {
            $query->where('status', WebhookStatus::Failed->value);
        } else {
            $this->components->error('Pass an event id or --failed.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($query->lazyById() as $record) {
            if ($record->status === WebhookStatus::Processed) {
                if (! $this->option('force')) {
                    $this->components->warn("{$record->stripe_event_id} was already processed (use --force).");

                    continue;
                }

                $record->forceFill(['status' => WebhookStatus::Pending])->save();
            }

            try {
                $processor->process($record);
                $this->components->task("{$record->stripe_event_id} ({$record->type})");
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("{$record->stripe_event_id}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
