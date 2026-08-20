<?php

declare(strict_types=1);

namespace TotpMfa\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Config\TotpMfa as TotpMfaConfig;
use TotpMfa\Models\RememberedDeviceModel;

/**
 * Lets a logged-in user see which devices can skip TOTP MFA, and revoke
 * them. Not wired into Shield's own routes (Shield doesn't know this
 * page exists) - add the routes from routes-snippet.php in this
 * package to your app/Config/Routes.php.
 */
class RememberedDevicesController extends Controller
{
    protected RememberedDeviceModel $devices;
    protected TotpMfaConfig $config;

    public function __construct()
    {
        $this->devices = model(RememberedDeviceModel::class);
        $this->config  = config('TotpMfa');
    }

    public function index(): string
    {
        $userId = auth()->id();

        $this->devices->purgeExpired();

        $devices = $this->devices->forUser($userId);

        $currentSelector = null;
        $cookie = $this->request->getCookie($this->config->rememberCookieName);

        if (is_string($cookie) && str_contains($cookie, ':')) {
            [$currentSelector] = explode(':', $cookie, 2);
        }

        return view($this->config->views['remembered_devices_index'], [
            'devices'         => $devices,
            'currentSelector' => $currentSelector,
        ]);
    }

    public function delete(int $id): RedirectResponse
    {
        $userId = auth()->id();

        $device = $this->devices->find($id);

        // Only ever let a user delete their own device record.
        if ($device === null || (int) $device['user_id'] !== $userId) {
            return redirect()->back()->with('error', 'Device not found.');
        }

        $this->devices->delete($id);

        // If they just revoked the device they're currently using,
        // also clear its cookie so this browser can't limp along on a
        // stale value until it naturally expires.
        $cookie = $this->request->getCookie($this->config->rememberCookieName);

        if (is_string($cookie) && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);

            if ($selector === $device['selector']) {
                $this->response->deleteCookie($this->config->rememberCookieName);
            }
        }

        return redirect()->back()->with('message', lang('TotpMfa.deviceRemoved'));
    }
}
