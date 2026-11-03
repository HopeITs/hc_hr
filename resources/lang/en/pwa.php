<?php

// "Install the app": header link, layout banner and the settings.app page (pwa-install.js).
return [
    'menu' => 'Install the app',
    'title' => 'Install the app',
    'lead' => 'Add HopeHR to this device so it opens from its own icon, like any other app.',
    'app-name' => 'HopeHR',
    'app-description' => 'Christian Hope Center’s HR system: your leaves, your profile and your team’s work.',
    'back' => 'My account',

    'status' => [
        'label' => 'On this device',
        'installed' => 'Installed',
        'ready' => 'Ready to install',
        'manual' => 'Install from the browser menu',
        'unsupported' => 'This browser cannot install apps',
    ],

    'install' => 'Install',
    'installing' => 'Opening the install window…',
    'outcome' => [
        'accepted' => 'HopeHR is installed. You can open it from its icon on this device.',
        'dismissed' => 'Not installed. You can install it later from this page.',
        'failed' => 'The browser did not open the install window. Use the steps below instead.',
    ],
    'installed-hint' => 'You are using the installed app.',

    'about' => [
        'title' => 'What installing does',
        'points' => [
            'HopeHR gets its own icon and opens in its own window, without the browser’s address bar.',
            'It still needs the internet. Without a connection it shows a notice and nothing is saved.',
            'No HR data is stored on the device: pages are always loaded fresh from the server.',
            'On a shared device, sign out when you finish, as you would in the browser.',
        ],
    ],

    'this-device' => 'Steps for this device',
    'other-devices' => 'Other devices and browsers',

    'platforms' => [
        'desktop-chromium' => [
            'title' => 'Windows or macOS: Chrome or Edge',
            'steps' => [
                'At the end of the address bar, select the install icon (a screen with an arrow). If it is not there, open the browser menu (⋮ or …).',
                'Chrome: choose “Cast, save and share”, then “Install page as app”. Edge: choose “Apps”, then “Install this site as an app”.',
                'Select Install. HopeHR opens in its own window and is added to the Start menu or Applications.',
            ],
        ],
        'desktop-safari' => [
            'title' => 'macOS: Safari 17 or later',
            'steps' => [
                'Open HopeHR in Safari.',
                'In the menu bar, choose File, then “Add to Dock”.',
                'Keep the name HopeHR and select Add. Open it from the Dock or Launchpad.',
            ],
        ],
        'android' => [
            'title' => 'Android: Chrome, Edge or Samsung Internet',
            'steps' => [
                'Open the browser menu (⋮).',
                'Tap “Install app” or “Add to Home screen”.',
                'Tap Install. The HopeHR icon appears on your home screen and in your apps.',
            ],
        ],
        'ios' => [
            'title' => 'iPhone or iPad: Safari',
            'steps' => [
                'Tap the Share button (a square with an arrow pointing up): at the bottom of the screen on iPhone, at the top on iPad.',
                'Scroll down and tap “Add to Home Screen”.',
                'Keep the name HopeHR and tap Add.',
                'Open HopeHR from its icon on the home screen.',
            ],
        ],
        'ios-non-safari' => [
            'title' => 'iPhone or iPad: Chrome, Edge or Firefox',
            'steps' => [
                'On iOS 16.4 or later, tap the Share button (in the address bar, or in the browser menu), then “Add to Home Screen”.',
                'Keep the name HopeHR and tap Add.',
                'If “Add to Home Screen” is not in the list, open this page in Safari and follow the Safari steps.',
            ],
        ],
        'firefox-android' => [
            'title' => 'Android: Firefox',
            'steps' => [
                'Open the Firefox menu (⋮).',
                'Tap “Add to Home screen” or “Install”.',
                'Confirm. The HopeHR icon appears on your home screen.',
            ],
        ],
        'firefox' => [
            'title' => 'Firefox on a computer',
            'steps' => [
                'Firefox on Windows, macOS or Linux cannot install web apps.',
                'To install HopeHR, open it in Chrome or Edge (Windows or macOS) or in Safari (macOS) and follow their steps.',
                'You can keep using HopeHR in Firefox; a bookmark brings you back to it.',
            ],
        ],
        'other' => [
            'title' => 'Other browsers',
            'steps' => [
                'Look in the browser menu for “Install”, “Add to Home screen” or “Add to Dock”.',
                'If there is no such option, open HopeHR in Chrome or Edge, or in Safari on an iPhone, iPad or Mac.',
            ],
        ],
    ],

    'banner' => [
        'label' => 'Install HopeHR',
        'text' => 'Install HopeHR on this device to open it from its own icon.',
        'how' => 'How to install',
        'dismiss' => 'Not now',
    ],
];
