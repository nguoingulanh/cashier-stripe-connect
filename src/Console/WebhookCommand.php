<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Console;

use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Nguoingulanh\CashierConnect\CashierConnect;
use Nguoingulanh\CashierConnect\Webhooks\PlatformWebhookListener;

final class WebhookCommand extends Command
{
    protected $signature = 'cashier-connect:webhook
        {--url= : The webhook URL (defaults to the package route)}
        {--api-version= : The Stripe API version the endpoint should use}
        {--disabled : Create the endpoint in a disabled state}';

    protected $description = 'Create the Stripe Connect webhook endpoint and print its signing secret';

    public function handle(CashierConnect $connect): int
    {
        $url = $this->stringOption('url') ?? route('cashier-connect.webhook');

        /** @var list<string> $events */
        $events = config('cashier-connect.webhook.events', []);

        $endpoint = $connect->stripe()->webhookEndpoints->create([
            'url' => $url,
            'connect' => true,
            'enabled_events' => $events,
            'api_version' => $this->stringOption('api-version') ?? Cashier::STRIPE_VERSION,
            'description' => 'Laravel Cashier Connect',
        ]);

        if ($this->option('disabled')) {
            $connect->stripe()->webhookEndpoints->update($endpoint->id, ['disabled' => true]);
        }

        $this->components->info("Connect webhook endpoint created: {$url}");
        $this->line('Add this to your .env file:');
        $this->newLine();
        $this->line("STRIPE_CONNECT_WEBHOOK_SECRET={$endpoint->secret}");
        $this->newLine();
        $this->components->warn(sprintf(
            'Optional: to receive %s events, enable them on your Cashier (platform) webhook endpoint.',
            implode(', ', PlatformWebhookListener::EVENTS),
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
