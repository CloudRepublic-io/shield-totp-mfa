<?php

declare(strict_types=1);

return [
    'heading'             => 'Verify it\'s you',
    'enrollIntro'         => 'Scan this QR code with Google Authenticator, Microsoft Authenticator, or a similar app.',
    'manualKeyIntro'      => 'Can\'t scan it? Enter this key manually instead:',
    'enrollCodeLabel'     => 'Enter the 6-digit code from your app to finish setup',
    'verifyIntro'         => 'Enter the 6-digit code from your authenticator app.',
    'codeLabel'           => 'Authentication code',
    'verifyButton'        => 'Verify',
    'rememberLabel'       => 'Remember this device for {days} days',
    'deviceNameLabel'     => 'Name this device (optional)',
    'deviceNamePlaceholder' => "e.g. \"Sarah's iPhone\"",
    'enterCode'           => 'Please enter the 6-digit code.',
    'invalidCode'         => 'That code is incorrect. Please try again.',
    'sessionExpired'      => 'Your setup session expired. Please start again.',
    'successMessage'      => 'Signed in successfully.',

    // Registration-time activator
    'activatorHeading'    => 'Set up two-factor authentication',
    'skipButton'          => 'Skip for now',

    // Standalone self-service settings (no dispatcher package needed)
    'settingsHeading'     => 'Authenticator app',
    'enrolledIntro'       => 'An authenticator app is currently protecting your account.',
    'notEnrolledIntro'    => "You haven't set up an authenticator app yet.",
    'enableButton'        => 'Set up authenticator app',
    'disableButton'       => 'Remove authenticator app',
    'disableConfirm'      => 'Remove your authenticator app? You will no longer be asked for a code at login.',
    'alreadyEnrolled'     => 'An authenticator app is already set up on this account.',
    'enabledMessage'      => 'Authenticator app enabled.',
    'disabledMessage'     => 'Authenticator app removed.',

    // Step-up auth for sensitive pages (RequireFreshTotp filter)
    'stepUpHeading'          => 'Confirm it\'s you',
    'stepUpIntro'            => 'This action requires confirming your identity. Enter the 6-digit code from your authenticator app.',
    'stepUpNeedsEnrollment'  => 'This action requires an authenticator app. Please set one up first.',

    // Remembered devices page
    'devicesHeading'      => 'Remembered devices',
    'devicesIntro'        => 'These devices can sign in without re-entering an authenticator code until they expire or you remove them.',
    'deviceColumnName'    => 'Device',
    'deviceColumnLastUsed' => 'Last used',
    'deviceColumnExpires' => 'Expires',
    'deviceColumnActions' => '',
    'thisDevice'          => '(this device)',
    'removeButton'        => 'Remove',
    'noDevices'           => 'You have no remembered devices.',
    'deviceRemoved'       => 'Device removed.',
];
