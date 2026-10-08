<?php

use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\WebhookReceived;
use Nguoingulanh\CashierConnect\Events\ConnectApplicationFeeRefunded;
use Nguoingulanh\CashierConnect\Events\ConnectTransferCreated;
use Nguoingulanh\CashierConnect\Events\ConnectTransferReversed;
use Nguoingulanh\CashierConnect\Webhooks\PlatformWebhookListener;

beforeEach(function () {
    fakeStripe();
    $this->account = shop()->createConnectAccount();
});

it('turns cashier platform webhooks into connect events', function (string $type, string $event, array $object) {
    Event::fake([$event]);

    // Real listener runs; only the Connect event is faked.
    app(PlatformWebhookListener::class)
        ->handle(new WebhookReceived(['type' => $type, 'data' => ['object' => $object + ['destination' => $this->account->stripe_account_id, 'account' => $this->account->stripe_account_id]]]));

    Event::assertDispatched($event, fn ($e) => $e->account?->is($this->account));
})->with([
    ['transfer.created', ConnectTransferCreated::class, ['id' => 'tr_1', 'object' => 'transfer']],
    ['transfer.reversed', ConnectTransferReversed::class, ['id' => 'tr_1', 'object' => 'transfer']],
    ['application_fee.refunded', ConnectApplicationFeeRefunded::class, ['id' => 'fee_1', 'object' => 'application_fee']],
]);

it('is registered as a listener of cashier webhooks', function () {
    expect(Event::hasListeners(WebhookReceived::class))->toBeTrue();
});

it('ignores unrelated platform events', function () {
    Event::fake([ConnectTransferCreated::class]);

    event(new WebhookReceived(['type' => 'customer.updated', 'data' => ['object' => ['id' => 'cus_1']]]));

    Event::assertNotDispatched(ConnectTransferCreated::class);
});
