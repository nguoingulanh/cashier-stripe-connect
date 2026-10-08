<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookReceived;
use Nguoingulanh\CashierConnect\Contracts\StripeGateway;
use Nguoingulanh\CashierConnect\Gateway\CashierStripeGateway;
use Nguoingulanh\CashierConnect\Http\Middleware\EnsureConnectReady;
use Nguoingulanh\CashierConnect\Webhooks\PlatformWebhookListener;

final class CashierConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cashier-connect.php', 'cashier-connect');

        $this->app->singleton(CashierConnect::class);

        $this->app->singleton(StripeGateway::class, fn () => new CashierStripeGateway(
            config('cashier-connect.api_base') ?: null,
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('cashier-connect.routes', true) && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/connect.php');
        }

        $this->app->make(Router::class)->aliasMiddleware('connect.ready', EnsureConnectReady::class);

        $this->app->make(Dispatcher::class)->listen(WebhookReceived::class, PlatformWebhookListener::class);

        if ($this->app->runningInConsole()) {
            $this->registerConsole();
        }
    }

    private function registerConsole(): void
    {
        $this->publishes([
            __DIR__.'/../config/cashier-connect.php' => config_path('cashier-connect.php'),
        ], 'cashier-connect-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cashier-connect-migrations');

        $this->commands([
            Console\InstallCommand::class,
            Console\WebhookCommand::class,
            Console\SyncCommand::class,
            Console\DoctorCommand::class,
            Console\PruneEventsCommand::class,
            Console\ReplayEventCommand::class,
        ]);

        if (config('cashier-connect.webhook.schedule_prune', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('cashier-connect:prune')->daily();
            });
        }
    }
}
