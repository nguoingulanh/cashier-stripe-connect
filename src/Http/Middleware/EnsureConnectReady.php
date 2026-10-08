<?php

declare(strict_types=1);

namespace Nguoingulanh\CashierConnect\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Nguoingulanh\CashierConnect\CashierConnect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks users whose connected account cannot accept charges and payouts yet.
 *
 *     Route::middleware(['auth', 'connect.ready'])          // 403
 *     Route::middleware(['auth', 'connect.ready:onboard'])  // redirect to Stripe onboarding
 */
final class EnsureConnectReady
{
    public function __construct(private readonly CashierConnect $connect) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            abort(403, 'Unauthenticated.');
        }

        $owner = $this->connect->for($user);

        if ($owner->isReady()) {
            return $next($request);
        }

        if ($mode === 'onboard' && ! $request->expectsJson()) {
            return new RedirectResponse($owner->onboardingUrl($request->fullUrl()));
        }

        abort(403, 'Your payout account setup is not complete.');
    }
}
