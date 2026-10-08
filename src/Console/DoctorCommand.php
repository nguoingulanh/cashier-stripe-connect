<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nguoingulanh\CashierConnect\CashierConnect;
use Throwable;

/**
 * Checks that everything the package needs is in place.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'cashier-connect:doctor {--offline : Skip checks that call the Stripe API}';

    protected $description = 'Check the Cashier Connect installation';

    private int $errors = 0;

    public function handle(CashierConnect $connect): int
    {
        $secret = (string) config('cashier.secret');

        $this->check('Stripe secret key (STRIPE_SECRET)', $secret !== '', 'Set STRIPE_SECRET in .env.');
        $this->check(
            'Connect webhook secret (STRIPE_CONNECT_WEBHOOK_SECRET)',
            filled(config('cashier-connect.webhook.secret')),
            'Run `php artisan cashier-connect:webhook` and copy the secret to .env.',
        );

        foreach (config('cashier-connect.tables') as $table) {
            $this->check("Table [{$table}]", Schema::hasTable($table), 'Run `php artisan migrate`.');
        }

        if (config('cashier-connect.routes')) {
            foreach (['cashier-connect.webhook', 'cashier-connect.onboarding.return', 'cashier-connect.onboarding.refresh'] as $route) {
                $this->check("Route [{$route}]", Route::has($route), 'Clear the route cache: `php artisan route:clear`.');
            }
        }

        if (! $this->option('offline') && $secret !== '') {
            $this->checkStripe($connect);
        }

        $this->newLine();

        if ($this->errors > 0) {
            $this->components->error("{$this->errors} problem(s) found.");

            return self::FAILURE;
        }

        $this->components->info('Cashier Connect is ready.');

        return self::SUCCESS;
    }

    private function checkStripe(CashierConnect $connect): void
    {
        try {
            $platform = $connect->stripe()->accounts->retrieve();
            $this->check("Stripe API reachable (platform {$platform->id})", true);
        } catch (Throwable $e) {
            $this->check('Stripe API reachable', false, $e->getMessage());

            return;
        }

        if (! Route::has('cashier-connect.webhook')) {
            return;
        }

        try {
            $url = route('cashier-connect.webhook');
            $found = false;

            foreach ($connect->stripe()->webhookEndpoints->all(['limit' => 100])->autoPagingIterator() as $endpoint) {
                if ($endpoint->url === $url && ($endpoint->status ?? 'enabled') === 'enabled') {
                    $found = true;
                    break;
                }
            }

            $this->check("Connect webhook endpoint for {$url}", $found, 'Run `php artisan cashier-connect:webhook`.', warning: true);
        } catch (Throwable $e) {
            $this->check('List webhook endpoints', false, $e->getMessage(), warning: true);
        }
    }

    private function check(string $label, bool $passed, string $hint = '', bool $warning = false): void
    {
        if ($passed) {
            $this->components->twoColumnDetail($label, '<fg=green;options=bold>OK</>');

            return;
        }

        if ($warning) {
            $this->components->twoColumnDetail($label, '<fg=yellow;options=bold>WARN</>');
        } else {
            $this->errors++;
            $this->components->twoColumnDetail($label, '<fg=red;options=bold>FAIL</>');
        }

        if ($hint !== '') {
            $this->line("  <fg=gray>→ {$hint}</>");
        }
    }
}
