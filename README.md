# Shield TOTP MFA (Google/Microsoft Authenticator) + Remember This Device

A drop-in set of Shield **Actions**, controllers, and a route filter
that add authenticator-app based MFA (Google Authenticator, Microsoft
Authenticator, Authy, 1Password, etc.) to a CodeIgniter Shield app:

- **`TotpMfa`** - the `'login'` action. Verification only: asks for a
  code, checks it. Nothing else.
- **`TotpActivator`** - the `'register'` action. Optional QR-code setup
  during signup, with a "skip for now" link.
- **`TotpSettingsController`** - a standalone self-service page
  (`account/totp`) for existing users to turn TOTP on or off any time,
  with no registration-time involvement and no dependency on the
  `shield-mfa-dispatcher` package.
- **`RequireFreshTotp`** - a route filter for step-up auth: force a
  fresh TOTP challenge before a specific sensitive page, even for a
  user who's already fully logged in.
- **`RememberedDevicesController`** - lets a user see and revoke
  devices (`account/devices`) that skip the login code prompt.

All of the above read/write the exact same permanent identity via one
shared class, `TotpIdentityStore`, so it doesn't matter which path a
user enrolled through - login verification, step-up, and the settings
pages all behave identically regardless.

Every view any of this renders can be overridden per-key from
`app/Config/TotpMfa.php`, the same way Shield itself lets you override
its own views (see "Overriding views" below).

Also included: an optional "remember this device for N days" checkbox
that skips the login code prompt on that browser until it expires or
is revoked.

No composer dependencies - the TOTP (RFC 6238) and Base32 (RFC 4648)
implementations are written from scratch in `src/Libraries/`.

## What's in the box

```
src/
  Authentication/Actions/
    TotpMfa.php                          <- 'login' action: verification only
    TotpActivator.php                    <- 'register' action: optional setup at signup
  Commands/Setup.php                     <- `php spark totp-mfa:setup`
  Config/TotpMfa.php                     <- all settings: TOTP params, remember-device,
                                             forced enrollment, step-up auth, view overrides
  Controllers/
    RememberedDevicesController.php      <- list/remove remembered devices
    TotpActivatorController.php          <- handles TotpActivator's "skip for now" link
    TotpSettingsController.php           <- standalone self-service enable/disable
    TotpStepUpController.php             <- the step-up challenge page
  Database/Migrations/..._CreateAuthRememberedDevices.php
  Filters/RequireFreshTotp.php           <- step-up auth filter for sensitive routes
  Language/en/TotpMfa.php
  Libraries/
    Base32.php                           <- RFC 4648, from scratch
    Totp.php                             <- RFC 6238, from scratch
    TotpIdentityStore.php                <- shared enrollment/verification logic
    CompletesPendingAction.php           <- shared "finish this pending action" trait
  Models/RememberedDeviceModel.php
  Views/
    totp_activator_enroll.php            <- QR + manual key + confirm + skip (registration)
    totp_mfa_verify.php                  <- plain code entry (login)
    totp_settings_index.php              <- standalone settings: status + enable/disable
    totp_settings_enroll.php             <- standalone settings: QR + confirm
    totp_step_up.php                     <- step-up challenge: plain code entry
    remembered_devices_index.php         <- list/remove devices
tests/TotpMfa/                           <- copy into your app's own tests/ folder - see "Tests"
routes-snippet.php                       <- routes to add by hand
```

## How the pieces fit together

```
Registration (optional)      Login (every time)          Settings page (any time)         Sensitive pages (any time)
────────────────────────     ───────────────────          ─────────────────────────         ───────────────────────────
TotpActivator                TotpMfa                       TotpSettingsController             RequireFreshTotp (filter)
  show()   -> QR code          show()   -> code prompt        index()  -> status               before() -> checks freshness,
  verify() -> confirm code     verify() -> check code         enroll() -> QR code                          redirects to challenge
TotpActivatorController        │                              confirm() -> confirm code                    if not fresh enough
  skip()   -> activate,        │                              disable() -> remove secret        TotpStepUpController
              no TOTP set up   │                                  │                                show()   -> code prompt
        │                     │                                  │                                verify() -> check code,
        └──────────┬──────────┴──────────────┬───────────────────┘                                          stamp session
                    │                         │                                                       │
                    ▼                         ▼                                                       ▼
                          TotpIdentityStore (shared) - reads/writes the same 'totp' identity
```

A user can end up TOTP-enrolled via any of the three left-hand paths;
`TotpMfa` (login) and `RequireFreshTotp` (step-up) don't know or care
which one they used - both just check `TotpIdentityStore::hasEnrolled()`.

**If you're using the separate `shield-mfa-dispatcher` package**
instead of (or alongside) `TotpSettingsController`, that package's own
`MfaSettingsController` is an alternative settings-page entry point -
it also goes through `TotpIdentityStore`, so a user enrolled via either
one is recognized identically by everything in this diagram. The two
controllers overlap in purpose (self-service enable/disable); use
whichever fits your app - `TotpSettingsController` if TOTP is your only
MFA method, the dispatcher's version if you offer several.

## Installation

### Option A - via Composer (recommended)

1. `composer require cloudrepublic/shield-totp-mfa`.
2. Run the setup command - publishes `Config/TotpMfa.php` and
   `Language/en/TotpMfa.php` into your app, the same way Shield's own
   `shield:setup` does:

   ```
   php spark totp-mfa:setup
   ```

   The migration is **not** copied anywhere - see step 5.

### Option B - manual drop-in

1. Copy `src/` into your app (e.g. `app/ThirdParty/TotpMfa/src`) and
   register the namespace in `app/Config/Autoload.php`:

   ```php
   public array $psr4 = [
       APP_NAMESPACE => APPPATH,
       'TotpMfa'     => APPPATH . 'ThirdParty/TotpMfa/src',
   ];
   ```

2. Copy `Config/TotpMfa.php` to `app/Config/TotpMfa.php`, and
   `Language/en/TotpMfa.php` to `app/Language/en/TotpMfa.php`.

   Do **not** also copy the migration into `app/Database/Migrations/` -
   see step 5 for why.

### Either way, finish with these

3. **Set an encryption key**, if you don't already have one (TOTP
   secrets are encrypted at rest - unlike a one-time code, they must
   stay decryptable, so they can't just be hashed):

   ```
   php spark key:generate
   ```

4. **Run the migration - no copying needed.** CodeIgniter's migration
   locator auto-discovers migrations directly from every registered
   namespace, exactly how Shield's own migrations get found:

   ```
   php spark migrate --all
   ```

   (or `php spark migrate -n TotpMfa` to target just this package). A
   plain `php spark migrate` with no flags only processes the `App`
   namespace by default, so it won't pick this up on its own.

   **If you're upgrading from an older copy of this package** that had
   you copy the migration into your app: delete that copy from
   `app/Database/Migrations/` first. Leaving both in place doesn't
   just duplicate effort - the moment anything migrates across all
   namespaces at once (`--all`, `-n TotpMfa`, or this package's own
   test suite), both copies try to create the same table and the
   second one fails with `table already exists` (an earlier version
   could even fail earlier than that, with a PHP "class already in
   use" fatal, if the copy was left over from a previous run of the
   setup command). Deleting the old file is safe either way - it won't
   drop a table it already created.

5. **Register the action(s)** in `app/Config/Auth.php`. Login
   verification is required; registration-time setup is optional:

   ```php
   public array $actions = [
       'register' => \TotpMfa\Authentication\Actions\TotpActivator::class, // optional
       'login'    => \TotpMfa\Authentication\Actions\TotpMfa::class,
   ];
   ```

   If you don't want a TOTP step at signup at all (relying only on
   self-service opt-in later), leave `'register' => null` (or whatever
   your app already uses there) and skip the routes below marked as
   `TotpActivator`-specific.

6. **Add whichever routes you need** from `routes-snippet.php` to
   `app/Config/Routes.php` - it now covers several optional pieces, so
   only add what you're actually using:
   - `TotpActivator`'s "skip" route (only if you registered it in step 5)
   - Remembered-devices management (`account/devices`)
   - Standalone settings (`account/totp`) - see "Standalone
     self-service enable/disable" below
   - The step-up challenge (`account/totp/step-up`) - only needed if
     you're also using the `RequireFreshTotp` filter, see "Step-up auth
     for sensitive pages" below

7. **Set your app's name** as `$issuer` in `app/Config/TotpMfa.php` (or
   `totpMfa.issuer` in `.env`) - shown above the code in the user's
   authenticator app.

### Optional add-ons covered later in this README

The steps above get login MFA (and, if you want it, registration-time
setup) working end to end. Everything else in this package is opt-in -
jump to whichever of these you need:

- **"Standalone self-service enable/disable"** - a page for existing
  users to turn TOTP on or off themselves, without going through
  registration.
- **"Forcing enrollment on next login"** - make TOTP mandatory for
  users who signed up before it was required.
- **"Step-up auth for sensitive pages"** - re-challenge an
  already-logged-in user before a specific sensitive action.
- **"Overriding views"** - point any of this package's pages at your
  own view files instead of the defaults.
- **"Tests"** - a ready-made test suite covering all of the above.

## Making registration-time setup mandatory instead of optional

`TotpActivator`'s enrollment view includes a "skip for now" link/form.
To make setup mandatory for every new user instead, just delete that
link/form from `Views/totp_activator_enroll.php` - the `skip()` method
and its route being reachable doesn't force anyone through them; it's
purely the view that offers the choice.

## Why this needed three attempts to get right

This is worth reading if you're modifying this package, since it's the
part most likely to break again on a future Shield version or a
different design choice.

Shield decides whether an action is "pending" purely by **whether an
identity of `getType()`'s type exists in the database at all** - not by
anything about that identity's state (confirmed vs pending, expired vs
not). `Email2FA` works because its one-time code identity gets deleted
the moment it's used, so that check correctly finds nothing on the next
request.

- **First attempt:** a single `'login'` action tried to handle both
  enrollment and verification, with `createIdentity()` as an
  unconditional no-op. Wrong: nothing was ever being persisted for a
  not-yet-enrolled user, so Shield's pending-check found nothing and
  skipped MFA entirely, enrollment included.
- **Second attempt:** same single action, but `createIdentity()` always
  persisted the real, permanent secret, distinguishing pending from
  confirmed via an `expires` column. This fixed enrollment showing up,
  but since a confirmed secret is deliberately never deleted, Shield's
  pending-check kept finding it on *every* subsequent request forever -
  login would appear to briefly succeed and then bounce straight back
  to the login form in an infinite loop.
- **Third attempt (current):** stop trying to make one `'login'` action
  do both jobs. `TotpMfa` (login) only ever reads the permanent secret
  and never creates one - a genuine, safe no-op, because by the time
  Shield ever routes a login attempt here at all, enrollment already
  happened somewhere else. `TotpActivator` (register) or the
  self-service settings page create it instead, using a *separate,
  disposable* identity type (`'totp_activate'`) during the brief window
  between "here's your QR code" and "you've confirmed a valid code" -
  this is what `TotpActivator`'s own `getType()`/`createIdentity()`
  operate on, never the permanent one.

If you installed an earlier copy of these files, replace them.
Symptoms of the earlier versions: the first showed no TOTP prompt at
all, for any account; the second showed the QR code and accepted a
correct code, but then bounced straight back to the login page on the
very next request instead of reaching the dashboard.

## Login completion: `completeLogin()`, not `login()`

Both `TotpMfa` and `TotpActivator` finish a pending action via
`auth('session')->getAuthenticator()->completeLogin($user)`. An earlier
version called `login($user)` instead, which fails specifically once a
permanent TOTP identity exists, with an error like:

> The user has identities for action, so cannot complete login... Or
> delete identities for action in database.

That's Shield's `login()` - a stricter, separate public method (used
for things like remember-me auto-login) that refuses if it finds
leftover identities for the pending action type, which a TOTP secret
always will, by design. `completeLogin()` is the method Shield's own
`Session::attempt()` actually calls to finish a pending action, and
doesn't have that restriction. Confirmed against Shield v1.3.0's real
source; worth a quick sanity check against whichever version you're
running if you hit login issues after upgrading Shield -
`vendor/codeigniter4/shield/src/Authentication/Authenticators/Session.php`.

## Design decisions worth knowing about

**Why `show()` uses a raw redirect + `exit` for the remembered-device
skip, instead of an elegant early return.** Shield's `ActionInterface`
contract types `show(): string` - it's meant to return a rendered view,
not a `Response`/`RedirectResponse`. Since we still want the *option*
to skip straight to a redirect when a valid device cookie is present,
we send the redirect directly (`redirect()->to(...)->send()`) and
`exit` before ever reaching a `return`.

**Why `handle()` just mirrors `show()`.** TOTP has no "send the code"
step the way email/SMS/WhatsApp MFA does - the code already lives in
the user's app. This is cheap insurance in case your installed Shield
version's `ActionController` POSTs to `handle` after the initial
`show`.

**Why the QR code renders client-side.** The `otpauth://` URI
(containing the secret) is written into the page and handed to a
client-side JS QR library (loaded from cdnjs) that draws it in the
browser. The secret is never sent to a third-party "generate my QR
code" API - only ever server → your user's own browser.

**Why `TotpActivator::verify()` checks `$user->active` before
activating/redirecting.** This class was written purely for
registration-time signup, but if you're using `shield-mfa-dispatcher`'s
`Config\MfaDispatcher::$requiredMethodsForGroups`, it gets reused
unmodified as a login-time forced-setup step too - an already-active
admin who's required to have TOTP but hasn't set it up yet gets routed
straight into this class's `show()`/`verify()`, mid-login. Blindly
calling `activate()` and redirecting to `registerRedirect()` would be
wrong for that case (there's no inactive account to activate, and the
user should land wherever a normal login sends them, not wherever a
fresh signup does) - so `verify()` checks whether the user was already
active *before* touching anything, and branches accordingly. This
class needed no other changes to support the dispatcher's forced-setup
flow - see that package's own README for the full explanation of why.

## Remember-device security notes

- The cookie stores a random **selector** (used to look the record up)
  and a random **validator** (never stored in plaintext - only its
  SHA-256 hash is kept in the database). This mirrors how CI4's own
  "remember me" login cookies work.
- The validator **rotates** on every successful use. A mismatched
  validator on an otherwise-recognized selector is treated as a stolen
  cookie and revokes the device outright, rather than quietly falling
  back to asking for MFA.
- Cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` by default
  (`$config->cookieSecure` - only turn this off for local HTTP
  development, never in production).
- Removing a device from the management page immediately invalidates
  it server-side; if it's the browser you're currently on, its cookie
  is cleared too.

## Testing your TOTP secret without a phone handy

While developing this package it was useful to have an authenticator in the browser, Chrome extension **2FA Authenticator** was used and provides some nice features that can help anyone wishing to contribute further to this plugin. Alternatively 
`TotpMfa\Libraries\Totp::currentCode($secret)` returns the code that
would currently validate for a given secret - also handy for development or a quick test script while wiring this up.

## If a page loads but shows nothing at all

All views extend `setting('Auth.views')['layout']` (Shield's own
layout by default) and must wrap their content in a section called
`'main'` - matching Shield's own `login.php` - not `'content'`, which
that layout never renders. If you still see nothing after confirming
that, check whether you're using a custom `Auth.views['layout']` and
what section name it actually renders.

## If you get "Call to undefined method TotpActivator::initController()"

Routes are dispatched through CodeIgniter's normal Controller
lifecycle (which calls `initController()` on whatever class is routed
to). `TotpActivator` is a Shield `ActionInterface` implementation, not
a `Controller`, and has no such method - `show()`/`handle()`/`verify()`
only ever get called indirectly, by Shield's own `ActionController`,
which is why they don't hit this. The "skip for now" link is different:
it needs its own real route, so it's handled by a small dedicated
`TotpActivatorController` instead (see `routes-snippet.php`) rather
than a method on the Action class itself. If you wired the skip route
directly at `TotpActivator::skip`, point it at
`TotpActivatorController::skip` instead.

## If "skip" (or a successful verify) doesn't actually get you past registration

If `TotpActivatorController::skip()` (or a normal successful
`TotpActivator::verify()`) appears to work - the identity gets deleted,
the user gets activated - but the very next page load bounces you
straight back into the pending registration flow (often with a fresh
QR code, since `show()` just regenerates one), the missing piece is
almost certainly session cleanup. `completeLogin()` alone was not
observed to be enough in every case: Shield's own pending-action
session markers (`auth_action`, `auth_action_message`) also need to be
cleared manually first. This is now handled centrally by the
`CompletesPendingAction` trait (used by `TotpMfa`, `TotpActivator`, and
`TotpActivatorController`) - if you're on an older copy of any of
these three files, replace them.

## If successful TOTP enrollment ever deletes an unrelated identity (e.g. `email_password`)

`TotpIdentityStore` now does two things specifically to rule this out:

1. Every read and write gets its own fresh `UserIdentityModel` instance
   (`model(UserIdentityModel::class, false)`) rather than one shared
   instance reused across several chained `where()`/`delete()` calls in
   the same request - which rules out query-builder conditions
   carrying over from one call into the next.
2. Type-scoped deletes go through Shield's own
   `deleteIdentitiesByType($user, $type)` helper (the same one the
   reference implementation this package was rebuilt against uses),
   rather than hand-rolled `where('type', ...)->delete()` chains.

If you're on an older copy of `TotpIdentityStore.php`, replace it. If
you still see an unrelated identity type disappear after this change,
that would be genuinely surprising and worth capturing a query log for
(`Config\Database`'s query logging, or the Debug Toolbar's Database tab)
at the exact moment of enrollment confirmation, since at that point the
cause would be somewhere outside this package's own code.

## If "remember this device" never seems to work, even when checked

This was a real bug: cookies were being set via a separately-fetched
`service('response')` instance, then a *different* response object
(returned by `redirect()->to(...)`) was what actually got sent to the
browser. `redirect()` is not guaranteed to return the same object as
`service('response')` - a cookie set on the wrong one is silently
dropped. Everything else worked (the database row for the remembered
device really was being created), which made this a confusing one:
the feature looked broken end-to-end even though only the cookie
delivery was actually failing.

Fixed by queuing the cookie (`queueDeviceCookie()`) and attaching it
directly onto the exact response object being returned/sent
(`applyQueuedCookie()`), in both `show()`'s remembered-device
short-circuit and `verify()`'s success path. If you're on an older
copy of `TotpMfa.php`, replace it.

## Standalone self-service enable/disable (no dispatcher package needed)

If you're not using the `shield-mfa-dispatcher` package, users who
skip TOTP setup at registration (via `TotpActivator`'s "skip for now"
link) still need some way to turn it on later. `TotpSettingsController`
provides a minimal standalone page for exactly that - just "set up" /
"remove", no method-switching concept:

- `GET  account/totp` - status page (enrolled or not)
- `GET  account/totp/enroll` - QR code + confirm form
- `POST account/totp/confirm` - confirms the code, enables TOTP
- `POST account/totp/disable` - removes the secret

Add the routes from `routes-snippet.php` and link to `account/totp`
from wherever your account settings page lives.

If you *are* using `shield-mfa-dispatcher`, use that package's
`MfaSettingsController` instead (it also handles switching between
several MFA methods, not just TOTP on/off) - both ultimately go through
the same `TotpIdentityStore`, so a user enrolled via either one is
recognized correctly by `TotpMfa` regardless of which page they used.

## Forcing enrollment on next login (`$config->forceEnrollmentOnNextLogin`)

For rolling out mandatory MFA to users who signed up before it was
required (or who used `TotpActivator`'s "skip for now" link), set this
in `app/Config/TotpMfa.php`:

```php
public bool $forceEnrollmentOnNextLogin = true;
```

The next time such a user logs in, they'll see the same QR-code setup
`TotpActivator` shows at registration - as part of that login attempt,
rather than being let through. Once confirmed, it's a normal permanent
enrollment; nothing else about how `TotpMfa` behaves afterward changes.

**Why this needed more than an `if` statement.** Normally, a user with
no TOTP identity at all never even reaches `TotpMfa::show()` - Shield's
own pending-check (`getIdentityByType($user, $action->getType())`)
finds nothing for them and completes the login before this action ever
runs. Forcing enrollment means `getType()` and `createIdentity()` both
need to become aware, per-user, of whether *this* user is enrolled:

- Already enrolled → `getType()` returns the permanent secret's type,
  same as always; `createIdentity()` stays a no-op. Unchanged, and
  still safe for the reasons in this file's class doc comment.
- Not enrolled + forcing → `getType()`/`createIdentity()` instead point
  at the same short-lived, disposable activation type `TotpActivator`
  uses (not the permanent one), so Shield's pending-check finds
  *something* and actually routes the login here instead of completing
  it. That identity gets deleted the moment enrollment is confirmed,
  exactly like a normal `TotpActivator` signup - it never risks
  becoming a second permanent identity or reintroducing the infinite
  loop described earlier in this README.

**Interaction with `TotpActivator`'s skip link:** if this is `true`, a
user who skips setup at registration will simply be asked again the
very next time they log in - skipping only defers it by one login, not
indefinitely. If you want a genuine long-term skip alongside mandatory
enrollment for everyone else, that needs its own exemption mechanism
(e.g. a flag on the user you check yourself) - not something this
package guesses at for you.

## Disabling TOTP also revokes remembered devices

`TotpIdentityStore::disable()` removes every remembered device for the
user alongside the secret itself, not just the secret. A remembered
device was only ever trusted in the context of "this browser already
passed a TOTP challenge for this account" - leaving those records
valid after removing TOTP would let a device skip MFA that no longer
exists, or (if TOTP is re-enabled later with a new secret) skip a
challenge it never actually passed. Both `TotpSettingsController` and
`shield-mfa-dispatcher`'s `MfaSettingsController` also clear the
current browser's cookie for tidiness, though the database-side
revocation is what actually matters security-wise.

## Step-up auth for sensitive pages (`RequireFreshTotp` filter)

Everything above concerns login. This is different: a route **filter**
that forces a fresh TOTP challenge before reaching a specific page,
even for a user who's already fully logged in - useful for gating
sensitive actions (updating payment/API settings, changing an email
address, etc.) behind re-confirmed identity, the way Stripe, GitHub,
and AWS all do before letting you touch billing or security settings.

### Setup

1. Register the filter alias in `app/Config/Filters.php`:

   ```php
   public array $aliases = [
       // ... your existing aliases
       'totp-fresh' => \TotpMfa\Filters\RequireFreshTotp::class,
   ];
   ```

2. Add the step-up challenge routes from `routes-snippet.php` (already
   included if you copied the whole snippet earlier).

3. Apply it to whichever routes need protecting, alongside your normal
   login-required filter:

   ```php
   $routes->group('admin/billing', ['filter' => ['session', 'totp-fresh']], static function ($routes) {
       $routes->get('stripe-settings', 'Admin\BillingController::index');
       $routes->post('stripe-settings', 'Admin\BillingController::update');
   });
   ```

That's it - a user reaching `admin/billing/stripe-settings` without a
recent-enough TOTP challenge gets sent to a short code-entry page
first, then bounced back to where they were headed.

### How freshness works

A timestamp is stashed in session the moment a challenge succeeds.
Subsequent requests to any `totp-fresh`-protected route within
`$config->stepUpFreshnessSeconds` (default 15 minutes) pass straight
through without asking again; after that window, the next protected
page reached asks again. This is deliberately independent of the
login-time "remember this device" cookie - that cookie is about
skipping ordinary login MFA for a trusted browser over weeks; this is
about re-confirming identity immediately before something sensitive,
on a much shorter timescale, regardless of whether the device is
"remembered" at login.

### What happens if the user has no TOTP secret at all

By default, `RequireFreshTotp` lets them through - there's nothing to
challenge them with, so the filter doesn't lock them out of a page
they have no way to unlock. If you'd rather force enrollment before
such pages are reachable at all, set:

```php
public bool $stepUpRequiresEnrollment = true;
public string $stepUpEnrollRouteName  = 'totp-settings-enroll'; // or
                                        // 'mfa-settings-totp-enroll'
                                        // if using shield-mfa-dispatcher
```

### This is separate machinery from the login Action, deliberately

`RequireFreshTotp`/`TotpStepUpController` don't touch Shield's
`ActionInterface`/pending-login mechanism at all - they're an ordinary
CodeIgniter filter and controller operating on `auth()->user()`. That's
intentional: step-up auth is a mid-session concept (protecting one
action within an already-authenticated visit), which doesn't map onto
Shield's "is this login attempt allowed to complete" model at all.
Trying to force it through the Action system would mean re-fighting
the exact getType()/createIdentity() problems documented earlier in
this README, for no benefit - a plain filter has none of those
constraints.

## Tests

`tests/TotpMfa/` contains a test suite covering everything from raw
TOTP math up through actual login/logout HTTP requests, scoped to the
behaviors this README documents: enrollment, login verification,
forced enrollment, remember-device, and the step-up filter.

**If you're using `shield-mfa-dispatcher`** (or anything else that
makes `Config\Auth::$actions` point at something other than `TotpMfa`/
`TotpActivator` directly), and you see "cannot get the pending
registration/login user" errors, two separate, confirmed issues were
involved - both are fixed in the current version, but it's worth
understanding both if you're debugging a variant of this yourself.

**The primary cause, confirmed via two rounds of real diagnostic runs
against a real app:** `DatabaseTestTrait`'s own `$refresh` resets the
*database* between test methods, but does nothing to the *session* or
to CI4's own cached *service* instances. Shield's own `Session`
authenticator throws ("The user has User Info in Session, so already
logged in or in pending login state...") when `attempt()` is called
while state from a previous `attempt()` is still present.

The first diagnostic round confirmed `session()->destroy()` alone was
**not** sufficient - a second `attempt()` in the same process still
threw the same exception even with the session destroyed in between.
That pointed at CI4's service container specifically: `auth()`'s
underlying authenticator is obtained through it, which returns a
shared instance by default, so its own in-memory state can survive a
plain `session()->destroy()` call. A second diagnostic round confirmed
adding `$this->resetServices()` (a `CIUnitTestCase`-provided method for
resetting cached service instances) resolves it. `setUp()` in both
files now calls **both** `resetServices()` and `session()->destroy()`,
in that order, before anything else.

**One more real regression this specific fix introduced, also fixed:**
per CodeIgniter's own docs, `resetServices()` also wipes the route
collection ("the RouteCollection will have no routes"). Without
reloading it, every `url_to()`/`route_to()` call in this package's own
views and filters (`auth-action-verify`, `totp-step-up`,
`totp-settings-enroll`, ...) throws "The route for ... cannot be
found" - confirmed as a real regression against a real app the moment
`resetServices()` shipped without its companion call.
`Services::routes()->loadRoutes()` is now called right after
`resetServices()` in all three affected test files
(`TotpMfaTest`, `TotpActivatorTest`, and `RequireFreshTotpTest` -
the last one added defensively, since it doesn't call
`resetServices()` itself but can still be affected by another test
file calling it earlier in the same PHPUnit process).

**A separate finding, revised from an earlier partial account:**
`Session::attempt()`'s own `startUpAction('login', $user)` call is
hardcoded to the `'login'` slot's action, regardless of whether the
user is active or not. When `'login'` is `MfaDispatcher` rather than
`TotpActivator` directly, this calls `MfaDispatcher::createIdentity()`,
which resolves to whatever that user's *login* method actually is
(their preference, or `$defaultMethod`) - not `TotpActivator`, even for
a brand-new, registering user.

Once the session-leakage bug above was properly fixed, it became clear
this affects **all five** tests in `TotpActivatorTest`, not just two -
confirmed by a real run where every one of them failed identically.
The earlier "3 of 5 pass" observation was itself an artifact of the
session-leakage bug: those three were very likely inheriting stray
pending-state left behind by whatever test happened to run immediately
before them, not passing due to anything correct in their own setup.

Two further fixes were tried and confirmed **not** sufficient, each
ruled out by a dedicated diagnostic run rather than assumption, before
finding the real one:

1. Creating the activation identity in the database alone (an earlier
   version of `simulateRegistrationStartup()`) - confirmed insufficient
   by a run that produced the exact same failures.
2. Writing `session('user')['auth_action']` directly, matching exactly
   what a genuinely working scenario's own session held - **also**
   confirmed insufficient. A reflection-based diagnostic dump of the
   authenticator object itself revealed why: `getPendingUser()` checks
   the authenticator's own private, in-memory `$userState` property
   directly, not something re-derived from session data on each call.
   A working scenario's `$userState` was directly observed to be `2`
   at the exact point `getPendingUser()` succeeded; a failing
   scenario's was `3`, even with byte-for-byte identical session data.

**The actual fix:** `simulateRegistrationStartup()` now sets both
`$userState` (to `2`, the confirmed-working value - `setAuthAction()`
itself never runs again after `Session::attempt()` completes to
reconsider its earlier decision) and `$user` directly via reflection on
the authenticator object, and is called by every test in the file. Real
registration works correctly in production because Shield's actual
registration flow reaches this state through a separate mechanism this
test suite's `attempt()`-based simulation can't reproduce - see that
method's own doc comment, and `TotpActivatorTest`'s class doc comment,
for the full account of both
issues and how they were told apart.

```
tests/TotpMfa/
  Libraries/Base32Test.php            <- pure codec round-trip, no DB/HTTP
  Libraries/TotpTest.php               <- self-consistency + official RFC 6238 vectors
  Libraries/TotpIdentityStoreTest.php  <- enrollment/confirm/disable, needs DB
  Authentication/Actions/TotpMfaTest.php       <- login verify, remember-device, forced enrollment
  Authentication/Actions/TotpActivatorTest.php <- registration-time QR/confirm/skip
  Filters/RequireFreshTotpTest.php      <- step-up challenge freshness logic
  LogoutTest.php                        <- sanity check, not a Shield logout re-test
```

### Setup

1. **Copy the whole `tests/TotpMfa` directory into your app's existing
   `tests/` folder** (`app_root/tests/TotpMfa/...`). These aren't
   designed to run standalone against just this package - they run
   against your actual app, the same way any of your own tests would,
   using whatever `Tests\` PSR-4 mapping and PHPUnit config your app
   already has.
2. Make sure your `tests` database group (`Config\Database`, or
   `database.tests.*` in `.env`) actually points at a working
   connection - see the SQLite gotcha below if you hit a connection
   error specifically.

   **Migrations run automatically, but only because of one specific
   line in each test class:** `protected $namespace = null;`.
   `DatabaseTestTrait`'s own default for `$namespace` is the literal
   string `'Tests\Support'`, **not** `null` - left at that default, it
   only looks for migrations under that one namespace, which contains
   neither Shield's own tables (`users`, `auth_identities`, etc.) nor
   this package's `auth_remembered_devices` migration, so nothing gets
   created at all (a `Table '...users' doesn't exist` error is the
   signature symptom). Setting it to `null` is what makes it behave
   like `php spark migrate --all` - every registered namespace, Shield
   included. Every test class in `tests/TotpMfa/` already sets this;
   if you write your own tests against this package, do the same.

   **Common gotcha:** CodeIgniter's default `tests` group uses SQLite,
   and on a minimal PHP image (many Docker containers included) the
   `php-sqlite3` extension often isn't installed, producing `Class
   "SQLite3" not found` for every database-backed test here, while the
   pure-PHP ones (`Base32Test`, `TotpTest`) still pass fine - that
   split is a reliable sign this is what's happening. Either install
   the extension (`docker-php-ext-install sqlite3 pdo_sqlite`, or your
   image's package manager equivalent), or point `database.tests.*` in
   `.env` at your real database engine instead (needs to be a separate
   database from your dev one, since these tests migrate and refresh
   it via `protected $refresh = true;`).
3. Make sure `TotpMfa` (and, if you're testing it,
   `TotpActivator`/its skip route) are registered exactly as described
   earlier in this README - these tests exercise your app's actual
   routes and config, not an isolated copy of them.
4. Run them the same way you run the rest of your suite, e.g.:

   ```
   vendor/bin/phpunit tests/TotpMfa
   ```

### Why some tests call the filter directly instead of hitting a route

`RequireFreshTotpTest` calls `RequireFreshTotp::before()` directly
rather than routing an HTTP request through a real protected page.
This avoids requiring your app to have a dedicated test-only protected
route just to exercise the filter - `before()` only needs a request
object and an "acting as" user, both of which
`CodeIgniter\Test\CIUnitTestCase` and Shield's own
`CodeIgniter\Shield\Test\AuthenticationTesting` trait already provide.

### Why the HTTP-level tests use `withSession(['auth_action' => ...])`

This mirrors Shield's own documented testing pattern (see
[Shield's testing reference](https://shield.codeigniter.com/references/testing/)):
`AuthenticationTesting::actingAs($user)` logs a user in for the test,
and separately setting the `auth_action` session key simulates
Shield's own "there's a pending action" state without needing to
replay a full password-login HTTP request first. It's the same
technique Shield's own test suite uses to test `Email2FA`.

### A skipped test isn't a failure

`TotpMfaTest::testForcedEnrollmentShowsQrCodeForUnenrolledUser()` calls
`markTestSkipped()` if `forceEnrollmentOnNextLogin` isn't enabled in
your test environment, since that's an opt-in, per-app setting - not
something this suite should assume either way.

## If disabling TOTP doesn't seem to remove remembered devices

`TotpIdentityStore::disable()` now deletes matching rows from
`auth_remembered_devices` using the query builder directly
(`db_connect()->table('auth_remembered_devices')->where('user_id', $user->id)->delete()`)
rather than going through `RememberedDeviceModel`'s own `delete()`
chain - this removes any dependency on Model-specific delete()
argument handling, so the operation is unambiguous: every row for that
`user_id`, gone. If you're on an older copy of `TotpIdentityStore.php`,
replace it. To confirm it's working, check the table directly after
disabling:

```sql
SELECT * FROM auth_remembered_devices WHERE user_id = <id>;
```

which should return nothing immediately after disabling TOTP from
`account/totp` (or the dispatcher package's equivalent).

## If the recorded IP address is your server's IP, not the visitor's

This isn't a bug in this package to fix - `TotpMfa` already calls the
correct, CodeIgniter-recommended API for this
(`$request->getIPAddress()`), and CodeIgniter's own documentation
confirms that method already accounts for a configured reverse proxy.
The actual cause is almost always that your app is running behind a
reverse proxy, load balancer, or local dev tunnel (very likely if
you're testing on a `*.local.*` domain) without telling CodeIgniter to
trust it. Without that, `getIPAddress()` correctly and safely falls
back to `REMOTE_ADDR` - which, behind a proxy, is the proxy's own IP,
not the visitor's - rather than blindly trusting a client-suppliable
header (doing otherwise was a real, patched CodeIgniter CVE:
[CVE-2022-23556](https://github.com/advisories/GHSA-ghw3-5qvm-3mqc)).

The fix is in your app's `app/Config/App.php`, not in this package:

```php
public array $proxyIPs = [
    '10.0.1.200'     => 'X-Forwarded-For', // your actual proxy's IP
    '192.168.5.0/24' => 'X-Forwarded-For', // or a whole trusted subnet
];
```

Set this to whatever your real reverse proxy/load balancer's IP (or
subnet) is, and the correct header it forwards the client IP in
(`X-Forwarded-For` is the most common). Once configured, every
`$request->getIPAddress()` call across your whole app - including the
one in `TotpMfa::maybeRememberDevice()` - starts returning the real
visitor IP automatically; nothing else needs to change.

## Overriding views

Every view this package renders is looked up through
`Config\TotpMfa::$views`, the same pattern Shield itself uses for
`Config\Auth::$views`. To use your own view instead of one of the
defaults, override its entry in your `app/Config/TotpMfa.php`:

```php
public array $views = [
    'totp_mfa_verify' => 'App\Views\auth\my_totp_verify',
    // any keys you don't list keep using this package's default
];
```

Your replacement doesn't need to live under any particular namespace -
anywhere `view()` can resolve works, including a plain path under
`app/Views/`. It does need to accept the same variables the default
expects; check the matching file under `src/Views/` for exactly what's
passed to each one. The full list of overridable keys:

| Key | Default | Passed to |
|---|---|---|
| `totp_mfa_verify` | `TotpMfa\Views\totp_mfa_verify` | `TotpMfa::show()` (login, already enrolled) |
| `totp_activator_enroll` | `TotpMfa\Views\totp_activator_enroll` | `TotpActivator::show()`, and `TotpMfa::show()` when forcing enrollment |
| `totp_settings_index` | `TotpMfa\Views\totp_settings_index` | `TotpSettingsController::index()` |
| `totp_settings_enroll` | `TotpMfa\Views\totp_settings_enroll` | `TotpSettingsController::enroll()` |
| `totp_step_up` | `TotpMfa\Views\totp_step_up` | `TotpStepUpController::show()` |
| `remembered_devices_index` | `TotpMfa\Views\remembered_devices_index` | `RememberedDevicesController::index()` |

## If you get "Cannot declare class ...CreateAuthRememberedDevices, because the name is already in use", or "table already exists" during migration

Both of these trace back to the same root cause, found across two
rounds of fixes: this package's migration was originally designed to
be **copied** into `app/Database/Migrations/`, but CodeIgniter's
migration locator already **auto-discovers** migrations directly from
every registered namespace - exactly how Shield's own migrations get
found, with no copying involved at all. Copying it on top of that
auto-discovery created two migrations doing the same job:

- **First symptom (fixed by no longer renaming a stale copy):** an
  earlier version of `totp-mfa:setup` gave the copied file a fresh
  timestamp on every run, but renaming a *file* doesn't change the
  *class name* declared inside it - running the command twice could
  leave two files both declaring `class CreateAuthRememberedDevices`,
  a fatal PHP error the moment both get loaded together.
- **Second symptom (fixed by not copying at all):** even with that
  first bug fixed, having both an app-copied file *and* the
  auto-discovered package copy still meant two migrations each trying
  to `CREATE TABLE auth_remembered_devices` - harmless until anything
  migrates across every namespace at once (`--all`, `-n TotpMfa`, or
  this package's own test suite via `$namespace = null`), at which
  point the second one to run fails with `table already exists`.

**The actual fix:** this package's migration is no longer copied
anywhere, by either `totp-mfa:setup` or the manual install steps - see
"Installation" above. `Setup.php` now also actively checks for and
warns about a leftover copy from an older version of this package.

**If you already have a copy in `app/Database/Migrations/`** (from an
older version of this package, before this fix): delete it. This is
always safe - deleting the file doesn't drop any table it already
created, and the auto-discovered copy takes over from there. The one
thing to watch for: if that old copy already ran successfully and
created the table, don't *also* run `php spark migrate --all` against
that same database expecting the auto-discovered copy to create it
again - it can't, the table's already there. This only matters for
your live app/dev database; an isolated test database that gets
refreshed on every run (`$refresh = true`, as this package's own tests
use) doesn't have this problem, since there's nothing pre-existing to
conflict with once the stale file is gone.

## If you get "There is a gap in the migration sequence near version number: ..."

This happens specifically if you're upgrading from a version of this
package that had you copy the migration into `app/Database/Migrations/`
(see the section above), and that copy already ran successfully in
your database before you removed it. CodeIgniter tracks *when* each
migration was applied, and when running across all namespaces at once
(`--all`, `-n TotpMfa`, or this package's own test suite), it expects
everything still-unmigrated to sort chronologically *after* whatever's
already been applied - finding this package's own migration dated
*earlier* than something already recorded as migrated is exactly what
it calls a "gap," and refuses rather than risk applying things out of
order.

Fixed by giving the package's own migration a later timestamp,
guaranteed to sort after anything left over from the old
copy-into-app pattern. If you're still seeing this after updating to
the latest copy of this package, the old app-copied file's *tracking
record* (not just the file itself) may still need cleaning up:

```sql
SELECT * FROM migrations WHERE class LIKE '%CreateAuthRememberedDevices%';
```

to see exactly what's recorded, and remove any row referencing the
old `App\Database\Migrations\CreateAuthRememberedDevices` version if
one remains after you've already deleted that file.
