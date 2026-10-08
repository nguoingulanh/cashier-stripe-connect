<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Console;

use Illuminate\Console\Command;
use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Nguoingulanh\CashierConnect\Services\AccountService;
use Throwable;

final class SyncCommand extends Command
{
    protected $signature = 'cashier-connect:sync
        {account? : A Stripe account id (acct_...)}
        {--all : Sync every connected account}';

    protected $description = 'Sync connected account status from Stripe';

    public function handle(AccountService $accounts): int
    {
        $id = $this->argument('account');

        if (is_string($id) && $id !== '') {
            $accounts->sync($accounts->findByStripeIdOrFail($id));
            $this->components->info("Synced [{$id}].");

            return self::SUCCESS;
        }

        if (! $this->option('all')) {
            $this->components->error('Pass an account id or --all.');

            return self::INVALID;
        }

        $failed = 0;

        AccountService::model()::query()->whereNull('deauthorized_at')->chunkById(100, function ($chunk) use ($accounts, &$failed) {
            /** @var ConnectedAccount $account */
            foreach ($chunk as $account) {
                try {
                    $accounts->sync($account);
                    $this->components->task($account->stripe_account_id);
                } catch (Throwable $e) {
                    $failed++;
                    $this->components->error("{$account->stripe_account_id}: {$e->getMessage()}");
                }
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
