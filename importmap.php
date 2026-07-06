<?php

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
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    'jquery' => ['version' => '4.0.0'],
    'datatables.net-bs5' => ['version' => '2.3.8'],
    'datatables.net' => ['version' => '2.3.8'],
    'datatables.net-bs5/css/dataTables.bootstrap5.min.css' => ['version' => '2.3.8', 'type' => 'css'],
    'tslib' => ['version' => '2.5.0'],
    '@fullcalendar/core' => ['version' => '5.11.5'],
    'preact' => ['version' => '10.12.1'],
    'preact/compat' => ['version' => '10.12.1'],
    '@fullcalendar/common' => ['version' => '5.11.5'],
    'preact/hooks' => ['version' => '10.12.1'],
    '@fullcalendar/daygrid' => ['version' => '5.11.5'],
    '@fullcalendar/timegrid' => ['version' => '5.11.5'],
    '@fullcalendar/interaction' => ['version' => '5.11.5'],
    '@fullcalendar/core/locales/fr' => ['version' => '5.11.5'],
    'fullcalendar' => ['version' => '5.11.5'],
];
