<?php

return [
    'plugin_name' => 'Minecraft Mods Addon',

    'page' => [
        'minecraft_version' => 'Minecraft Version',
        'loader' => 'Loader',
        'unknown' => 'Unknown',
    ],

    'table' => [
        'columns' => [
            'date_modified' => 'Modified',
        ],
    ],

    'modals' => [
        'stop_server_hint' => 'It is recommended to stop the server first.',
    ],

    'mods' => [
        'installed_count' => 'Installed Mods',
        'installed_count_state' => ':count (:linked linked to Modrinth)',
        'list_failed' => 'The mods folder could not be read',
        'empty_heading' => 'No mods installed',
        'empty_description' => 'Turn off "Only show installed" to install mods from Modrinth, or upload jar files to the mods folder.',
        'unknown_version_hint' => 'The installed version is unknown, so every version on Modrinth is offered as an update.',
        'loader_mismatch' => 'This mod was not made for the loader of this server.',
        'client_only' => 'This mod only runs on the client and may keep the server from starting.',
        'filter_not_linked' => 'Not linked',
        'columns' => [
            'name' => 'Name',
            'version' => 'Version',
            'source' => 'Source',
            'size' => 'Size',
        ],
        'status' => [
            'linked' => 'Modrinth',
            'modpack' => 'Modpack',
            'detected' => 'Found on Modrinth',
            'manual' => 'Uploaded',
            'pending' => 'Analysing',
            'missing' => 'File missing',
        ],
        'status_hints' => [
            'linked' => 'Linked to Modrinth, updates are offered here and in the list of all mods.',
            'modpack' => 'Part of the installed modpack.',
            'detected' => 'Uploaded by hand, Modrinth knows this exact file. Link it to receive updates.',
            'manual' => 'Uploaded by hand and not found on Modrinth. Link it to a Modrinth project to receive updates.',
            'pending' => 'The file is being read in the background.',
            'missing' => 'Listed as installed, but the file is gone from the mods folder.',
        ],
        'scan_errors' => [
            'too_large' => 'The file is too large to be read, name, version and loader are guessed from the file name.',
            'unreadable' => 'The file could not be read, name, version and loader are guessed from the file name.',
        ],
        'scan' => [
            'running' => 'Reading uploaded mods in the background…',
            'running_progress' => 'Reading uploaded mods in the background (:current/:total)…',
        ],
        'actions' => [
            'update' => 'Update',
            'up_to_date' => 'Up to date',
            'link' => 'Link to Modrinth',
            'relink' => 'Change Modrinth link',
            'link_detected' => 'Link all found mods',
            'unlink' => 'Remove Modrinth link',
            'remove_entry' => 'Remove entry',
            'delete' => 'Delete',
            'rescan' => 'Analyse again',
            'rescan_hint' => 'Read every mod again and look it up on Modrinth',
        ],
        'link' => [
            'heading' => 'Link :name to Modrinth',
            'description' => 'Choose the Modrinth project and version of :file. The file stays as it is, from now on it is updated like a mod installed from Modrinth.',
            'exact_match' => 'Modrinth knows this exact file: :name :version.',
            'project' => 'Modrinth project',
            'project_helper' => 'Search by name, or paste a Modrinth link or slug.',
            'version' => 'Installed version',
            'version_helper' => 'Version according to the file: :version',
            'unknown_version' => 'Unknown version (offer every version as update)',
            'detected_description' => 'Links :count mods that Modrinth recognised by their exact file. Mods whose project is already linked to another file are skipped.',
        ],
        'update' => [
            'heading' => 'Update :name',
            'description' => 'Replaces version :old_version with the selected version.',
            'modpack_hint' => 'This mod is part of the installed modpack. Updating it on its own may break the modpack, an update of the modpack does not take it back.',
            'version' => 'Version',
            'version_helper' => 'Versions matching the loader and Minecraft version of this server.',
        ],
        'unlink' => [
            'heading' => 'Remove Modrinth link of :name',
            'description' => ':file stays on the server, it just isn\'t updated through Modrinth anymore.',
            'missing_description' => ':file is no longer on the server, this removes it from the installed list.',
        ],
        'delete' => [
            'heading' => 'Delete :name',
            'description' => 'This permanently deletes :file from the mods folder.',
            'modpack_hint' => 'This mod is part of the installed modpack, the modpack may not work without it.',
        ],
        'notifications' => [
            'linked' => 'Mod linked',
            'linked_body' => ':file is now linked to :name',
            'linked_detected' => ':count mods linked to Modrinth',
            'link_failed' => 'Linking failed',
            'unlinked' => 'Modrinth link removed',
            'unlink_failed' => 'Removing the link failed',
            'updated' => 'Mod updated',
            'updated_body' => ':name was updated to version :version',
            'update_failed' => 'Update failed',
            'deleted' => 'Mod deleted',
            'deleted_body' => ':file was deleted',
            'delete_failed' => 'Deleting failed',
            'scan_queued' => 'The mods are read again in the background',
            'scan_running' => 'The mods are already being read',
        ],
    ],

    'errors' => [
        'already_linked' => ':name is already linked to :file. Remove that link first.',
        'no_download' => 'The selected version has no file that can be downloaded.',
        'mod_not_found' => ':file is no longer in the mods folder.',
        'project_not_found' => 'The selected project could not be found on Modrinth.',
        'version_not_found' => 'The selected version could not be found on Modrinth.',
        'invalid_path' => 'Invalid file name: :path',
        'daemon_unreachable' => 'The node could not be reached.',
    ],
];
