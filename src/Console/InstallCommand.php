<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Console;

use Illuminate\Console\Command;

/**
 * Optional guided setup. The package works without running it.
 */
final class InstallCommand extends Command
{
    protected $signature = 'cashier-connect:install';

    protected $description = 'Guided setup for Cashier Connect (all steps are optional)';

    public function handle(): int
    {
        if ($this->confirm('Publish the config file (config/cashier-connect.php)?', false)) {
            $this->call('vendor:publish', ['--tag' => 'cashier-connect-config']);
        }

        if ($this->confirm('Run the database migrations now?', true)) {
            $this->call('migrate');
        }

        if ($this->confirm('Create the Connect webhook endpoint on Stripe?', false)) {
            $this->call('cashier-connect:webhook', array_filter([
                '--url' => $this->ask('Public webhook URL (leave empty to use the app URL)'),
            ]));
        }

        $this->call('cashier-connect:doctor', ['--offline' => true]);

        return self::SUCCESS;
    }
}
