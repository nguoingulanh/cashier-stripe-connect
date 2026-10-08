<?php

namespace Nguoingulanh\CashierConnect\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\CashierServiceProvider;
use Nguoingulanh\CashierConnect\CashierConnectServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CashierServiceProvider::class,
            CashierConnectServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        // CI runs the suite against MySQL / PostgreSQL by setting DB_CONNECTION.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
        $app['config']->set('cashier.secret', 'sk_test_fake');
        $app['config']->set('cashier-connect.webhook.secret', 'whsec_test');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // MySQL / PostgreSQL keep tables between tests, unlike in-memory SQLite.
        Schema::dropIfExists('users');
        Schema::dropIfExists('shops');
        $this->beforeApplicationDestroyed(function () {
            Schema::dropIfExists('users');
            Schema::dropIfExists('shops');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('stripe_id')->nullable();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }
}
