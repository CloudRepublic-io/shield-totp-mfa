<?php
/**
 * Add these to app/Config/Routes.php. Shield doesn't know about these
 * controllers/routes, so they need to be added by hand.
 */

// The "skip for now" link on TotpActivator's enrollment view. This is
// NOT wrapped in the 'session' filter (or any full-login-required
// filter) - it's reached by a user who is mid-registration (Shield's
// "pending" state), not someone already fully logged in. Wrapping it
// in a filter that requires full login would make it unreachable,
// exactly the kind of chicken-and-egg problem the rest of Shield's own
// auth/a/* routes are designed to avoid.
//
// Note this points at TotpActivatorController, NOT at TotpActivator
// (the Action class) directly - routes are dispatched through
// CodeIgniter's Controller lifecycle (initController(), etc.), which
// an ActionInterface implementation doesn't have. Pointing a route
// straight at an Action class fails with "Call to undefined method
// ...::initController()".
$routes->post(
    'auth/a/totp-activator/skip',
    '\TotpMfa\Controllers\TotpActivatorController::skip',
    ['as' => 'totp-activator-skip']
);

// These, on the other hand, are for an already-fully-logged-in user
// managing their own remembered devices, so the 'session' filter is
// correct here.
$routes->group('', ['filter' => 'session'], static function ($routes) {
    $routes->get(
        'account/devices',
        '\TotpMfa\Controllers\RememberedDevicesController::index',
        ['as' => 'account-devices']
    );

    $routes->post(
        'account/devices/(:num)/delete',
        '\TotpMfa\Controllers\RememberedDevicesController::delete/$1',
        ['as' => 'account-devices-delete']
    );

    // Standalone self-service enable/disable, usable without the
    // shield-mfa-dispatcher package - see TotpSettingsController.
    $routes->get(
        'account/totp',
        '\TotpMfa\Controllers\TotpSettingsController::index',
        ['as' => 'totp-settings']
    );

    $routes->get(
        'account/totp/enroll',
        '\TotpMfa\Controllers\TotpSettingsController::enroll',
        ['as' => 'totp-settings-enroll']
    );

    $routes->post(
        'account/totp/confirm',
        '\TotpMfa\Controllers\TotpSettingsController::confirm',
        ['as' => 'totp-settings-confirm']
    );

    $routes->post(
        'account/totp/disable',
        '\TotpMfa\Controllers\TotpSettingsController::disable',
        ['as' => 'totp-settings-disable']
    );

    // Step-up challenge shown by the RequireFreshTotp filter. Wrapped
    // in the 'session' filter here too - this challenges an EXISTING,
    // already-logged-in session, it doesn't log anyone in, so it
    // makes no sense to reach without already being logged in.
    $routes->get(
        'account/totp/step-up',
        '\TotpMfa\Controllers\TotpStepUpController::show',
        ['as' => 'totp-step-up']
    );

    $routes->post(
        'account/totp/step-up',
        '\TotpMfa\Controllers\TotpStepUpController::verify',
        ['as' => 'totp-step-up-verify']
    );
});
