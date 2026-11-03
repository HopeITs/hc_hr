<?php

namespace App\Livewire\Settings;

use Livewire\Component;

/**
 * "Install the app" (settings.app). Nothing here reaches the server: whether
 * the app is installed, whether the browser offers a prompt and which device
 * this is are all known only in the browser (resources/js/pwa-install.js).
 * The page renders the steps for every platform and Alpine shows the one that
 * matches; the disclosure lists the rest.
 */
class InstallApp extends Component
{
    /**
     * Every platform pwa-install.js can detect, in the order the "other
     * devices" disclosure lists them. Their words are in lang/{en,ar}/pwa.php.
     */
    public const PLATFORMS = [
        'desktop-chromium',
        'desktop-safari',
        'android',
        'ios',
        'ios-non-safari',
        'firefox-android',
        'firefox',
        'other',
    ];

    /** The disclosure: the four common ones, not the fallbacks. */
    public const OTHER_DEVICES = ['desktop-chromium', 'desktop-safari', 'android', 'ios'];

    public function render()
    {
        return view('livewire.settings.install-app', [
            'platforms' => self::PLATFORMS,
            'otherDevices' => self::OTHER_DEVICES,
        ]);
    }
}
