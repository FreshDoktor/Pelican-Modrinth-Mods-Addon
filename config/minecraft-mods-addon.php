<?php

return [
    // Largest mod jar (in MB) that is read to find out its name, version and loader. Bigger ones are
    // only judged by their file name and can't be recognised on Modrinth automatically.
    'max_mod_scan_size_mb' => env('MINECRAFT_MODS_ADDON_MAX_SCAN_SIZE_MB', 64),

    // Hosts an updated mod may be downloaded from.
    'allowed_download_hosts' => [
        'cdn.modrinth.com',
        'github.com',
        'raw.githubusercontent.com',
        'gitlab.com',
    ],
];
