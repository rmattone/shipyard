<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Self-update
    |--------------------------------------------------------------------------
    |
    | Where the ShipYard checkout lives as seen from inside the containers
    | (docker-compose.yml mounts it at /var/www/shipyard), which GitHub
    | repository and branch the updater follows, and the version string used
    | when the VERSION file cannot be read at all.
    |
    */

    'install_dir' => env('SHIPYARD_INSTALL_DIR'),

    'repo' => env('SHIPYARD_REPO', 'rmattone/shipyard'),

    'branch' => env('SHIPYARD_BRANCH', 'main'),

    'fallback_version' => '1.0.0',

];
