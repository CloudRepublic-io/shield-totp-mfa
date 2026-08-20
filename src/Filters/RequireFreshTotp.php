<?php

declare(strict_types=1);

namespace TotpMfa\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use TotpMfa\Libraries\TotpIdentityStore;

/**
 * Forces a fresh TOTP challenge before a protected route is reached -
 * "step-up" auth for sensitive actions (changing payment/API settings,
 * for example), on top of and separate from ordinary login MFA.
 *
 * Register the alias in app/Config/Filters.php:
 *
 *   public array $aliases = [
 *       ...
 *       'totp-fresh' => \TotpMfa\Filters\RequireFreshTotp::class,
 *   ];
 *
 * then apply it alongside your normal login-required filter (this one
 * only concerns TOTP freshness - it does not check login status
 * itself, and assumes something else already has):
 *
 *   $routes->group('admin/billing', ['filter' => ['session', 'totp-fresh']], static function ($routes) {
 *       // ... routes for changing Stripe keys, etc.
 *   });
 *
 * How it works: the first time a request hits a route wearing this
 * filter, the user is redirected to a short code-entry challenge
 * (TotpStepUpController) and then bounced back to whatever they were
 * originally trying to reach. A timestamp is stashed in session on
 * success; subsequent requests within $config->stepUpFreshnessSeconds
 * pass straight through without asking again. This is intentionally
 * independent of the "remember this device" cookie used at login -
 * that cookie is about skipping ordinary login MFA for a trusted
 * browser over weeks; this is about re-confirming identity within a
 * single sensitive action, on a much shorter timescale, regardless of
 * whether the device is "remembered" at login.
 */
class RequireFreshTotp implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = auth()->user();

        if ($user === null) {
            // Not this filter's job - let whatever login-required
            // filter runs alongside it (e.g. Shield's 'session')
            // handle an unauthenticated request.
            return null;
        }

        $config = config('TotpMfa');
        $store  = new TotpIdentityStore();

        if (! $store->hasEnrolled($user)) {
            if (! $config->stepUpRequiresEnrollment) {
                // Policy default: nothing to challenge them with, so
                // don't lock them out of a page they have no way to
                // unlock. Set $config->stepUpRequiresEnrollment = true
                // if you'd rather send them to enroll first instead.
                return null;
            }

            session()->set('totp_step_up_redirect', (string) current_url(true));

            return redirect()->route($config->stepUpEnrollRouteName)
                ->with('message', lang('TotpMfa.stepUpNeedsEnrollment'));
        }

        $verifiedAt = session($config->stepUpSessionKey);

        if (is_int($verifiedAt) && (time() - $verifiedAt) < $config->stepUpFreshnessSeconds) {
            return null; // still fresh - let the request through
        }

        session()->set('totp_step_up_redirect', (string) current_url(true));

        return redirect()->route('totp-step-up');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after the controller runs.
    }
}
