<?php

use Nguoingulanh\CashierConnect\Facades\CashierConnect;
use Nguoingulanh\CashierConnect\Testing\FakeStripeGateway;
use Nguoingulanh\CashierConnect\Tests\Fixtures\Shop;
use Nguoingulanh\CashierConnect\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Contract');

function fakeStripe(): FakeStripeGateway
{
    return CashierConnect::fake();
}

function shop(array $attributes = []): Shop
{
    return Shop::create($attributes + ['name' => 'Acme', 'email' => 'owner@acme.test']);
}

/**
 * Build a signed Connect webhook request body and headers.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function signedWebhook(array $event, string $secret = 'whsec_test', ?int $timestamp = null): array
{
    $event += [
        'id' => 'evt_'.bin2hex(random_bytes(8)),
        'object' => 'event',
        'api_version' => '2025-01-01',
        'created' => time(),
        'livemode' => false,
        'pending_webhooks' => 1,
        'request' => ['id' => null, 'idempotency_key' => null],
    ];

    $payload = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return [$payload, [
        'Stripe-Signature' => "t={$timestamp},v1={$signature}",
        'Content-Type' => 'application/json',
    ]];
}
