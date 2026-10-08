<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Nguoingulanh\CashierConnect\Exceptions\InvalidConnectWebhook;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Verifies the Stripe-Signature header against the Connect endpoint secret.
 *
 * Several comma-separated secrets are accepted to allow secret rotation.
 */
final class VerifyConnectSignature
{
    public const EVENT_ATTRIBUTE = 'cashier_connect_event';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secrets = array_filter(array_map('trim', explode(',', (string) config('cashier-connect.webhook.secret'))));

        if ($secrets === []) {
            // 500 makes Stripe retry, so no event is lost while the secret is being configured.
            Log::channel(config('cashier-connect.log_channel'))->error(InvalidConnectWebhook::missingSecret()->getMessage());

            return new Response('Connect webhook secret is not configured.', 500);
        }

        $event = null;

        foreach ($secrets as $secret) {
            try {
                $event = Webhook::constructEvent(
                    $request->getContent(),
                    (string) $request->header('Stripe-Signature'),
                    $secret,
                    (int) config('cashier-connect.webhook.tolerance', Webhook::DEFAULT_TOLERANCE),
                );

                break;
            } catch (SignatureVerificationException) {
                continue;
            } catch (UnexpectedValueException) {
                return new Response('Invalid payload.', 400);
            }
        }

        if (! $event instanceof Event) {
            return new Response('Invalid signature.', 400);
        }

        $request->attributes->set(self::EVENT_ATTRIBUTE, $event);

        return $next($request);
    }
}
