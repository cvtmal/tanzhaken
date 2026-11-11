<?php

declare(strict_types=1);

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'password-toggle' => [
        'path' => './assets/js/password-toggle.js',
        'entrypoint' => true,
    ],
    'dialog' => [
        'path' => './assets/js/dialog.js',
        'entrypoint' => true,
    ],
    'toast' => [
        'path' => './assets/js/toast.js',
        'entrypoint' => true,
    ],
    'alert' => [
        'path' => './assets/js/alert.js',
        'entrypoint' => true,
    ],
    'checkin-select' => [
        'path' => './assets/js/checkin-select.js',
        'entrypoint' => true,
    ],
    'credit-amount' => [
        'path' => './assets/js/credit-amount.js',
        'entrypoint' => true,
    ],
    'checkin-live' => [
        'path' => './assets/js/checkin-live.js',
        'entrypoint' => true,
    ],
];
