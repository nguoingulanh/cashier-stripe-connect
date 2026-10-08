<?php

use Nguoingulanh\CashierConnect\Models\ConnectedAccount;
use Nguoingulanh\CashierConnect\Models\ConnectWebhookEvent;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Account Settings
    |--------------------------------------------------------------------------
    |
    | Used when creating a connected account without explicit parameters.
    | Capabilities are only requested for "express" and "custom" accounts.
    |
    */

    'default_type' => env('CASHIER_CONNECT_TYPE', 'express'),

    'default_country' => env('CASHIER_CONNECT_COUNTRY'),

    'capabilities' => ['card_payments', 'transfers'],

    'prefill_email' => true,

    /*
    |--------------------------------------------------------------------------
    | Multiple Accounts
    |--------------------------------------------------------------------------
    |
    | When false, each model (User, Shop, ...) may own a single connected
    | account. Set to true to allow a model to own several accounts.
    |
    */

    'multiple_accounts' => false,

    /*
    |--------------------------------------------------------------------------
    | Connect Webhook
    |--------------------------------------------------------------------------
    |
    | Stripe sends events from connected accounts to a dedicated "Connect"
    | endpoint with its own signing secret. Run `cashier-connect:webhook`
    | to create the endpoint and obtain the secret.
    |
    | When "queue" is null, events are handled synchronously.
    |
    */

    'webhook' => [
        'secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_CONNECT_WEBHOOK_TOLERANCE', 300),
        'path' => 'stripe/connect/webhook',
        'queue' => env('CASHIER_CONNECT_QUEUE'),
        'queue_connection' => env('CASHIER_CONNECT_QUEUE_CONNECTION'),
        'events' => [
            'account.updated',
            'account.application.deauthorized',
            'capability.updated',
            'person.updated',
            'payout.paid',
            'payout.failed',
            'payout.canceled',
        ],
        'prune_after_days' => 30,
        'schedule_prune' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding
    |--------------------------------------------------------------------------
    |
    | "return_url" is where users land after finishing (or leaving) the
    | Stripe hosted onboarding. "link_ttl" (minutes) is how long the
    | signed return / refresh URLs handed to Stripe remain valid.
    |
    */

    'onboarding' => [
        'path_prefix' => 'stripe/connect',
        'return_url' => env('CASHIER_CONNECT_RETURN_URL', '/'),
        'link_ttl' => 1440,
        'collect' => 'currently_due',
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Set to false to register the webhook and onboarding routes yourself.
    |
    */

    'routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Models & Tables
    |--------------------------------------------------------------------------
    */

    'models' => [
        'account' => ConnectedAccount::class,
        'webhook_event' => ConnectWebhookEvent::class,
    ],

    'tables' => [
        'accounts' => 'stripe_connected_accounts',
        'events' => 'stripe_connect_webhook_events',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging & Stripe
    |--------------------------------------------------------------------------
    |
    | "api_base" overrides the Stripe API base URL (e.g. stripe-mock in CI).
    |
    */

    'log_channel' => env('CASHIER_CONNECT_LOG_CHANNEL'),

    'api_base' => env('CASHIER_CONNECT_API_BASE'),

];
