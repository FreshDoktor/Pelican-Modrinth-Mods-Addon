<?php

return [
    'plugin_name' => 'Minecraft Mods Addon',

    'page' => [
        'minecraft_version' => 'Minecraft-Version',
        'loader' => 'Loader',
        'unknown' => 'Unbekannt',
    ],

    'table' => [
        'columns' => [
            'date_modified' => 'Geändert',
        ],
    ],

    'modals' => [
        'stop_server_hint' => 'Es wird empfohlen, den Server vorher zu stoppen.',
    ],

    'mods' => [
        'installed_count' => 'Installierte Mods',
        'installed_count_state' => ':count (:linked mit Modrinth verknüpft)',
        'list_failed' => 'Der Mods-Ordner konnte nicht gelesen werden',
        'empty_heading' => 'Keine Mods installiert',
        'empty_description' => 'Schalte „Nur installierte anzeigen“ aus, um Mods von Modrinth zu installieren, oder lade JAR-Dateien in den Ordner mods hoch.',
        'unknown_version_hint' => 'Die installierte Version ist unbekannt, daher wird jede Version auf Modrinth als Aktualisierung angeboten.',
        'loader_mismatch' => 'Diese Mod wurde nicht für den Loader dieses Servers gemacht.',
        'client_only' => 'Diese Mod läuft nur auf dem Client und kann den Start des Servers verhindern.',
        'filter_not_linked' => 'Nicht verknüpft',
        'columns' => [
            'name' => 'Name',
            'version' => 'Version',
            'source' => 'Herkunft',
            'size' => 'Größe',
        ],
        'status' => [
            'linked' => 'Modrinth',
            'modpack' => 'Modpack',
            'detected' => 'Auf Modrinth gefunden',
            'manual' => 'Hochgeladen',
            'pending' => 'Wird analysiert',
            'missing' => 'Datei fehlt',
        ],
        'status_hints' => [
            'linked' => 'Mit Modrinth verknüpft, Aktualisierungen werden hier und in der Liste aller Mods angeboten.',
            'modpack' => 'Teil des installierten Modpacks.',
            'detected' => 'Manuell hochgeladen, Modrinth kennt genau diese Datei. Verknüpfe sie, um Aktualisierungen zu erhalten.',
            'manual' => 'Manuell hochgeladen und auf Modrinth nicht gefunden. Verknüpfe sie mit einem Modrinth-Projekt, um Aktualisierungen zu erhalten.',
            'pending' => 'Die Datei wird im Hintergrund gelesen.',
            'missing' => 'Als installiert eingetragen, aber die Datei fehlt im Ordner mods.',
        ],
        'scan_errors' => [
            'too_large' => 'Die Datei ist zu groß zum Lesen, Name, Version und Loader werden aus dem Dateinamen abgeleitet.',
            'unreadable' => 'Die Datei konnte nicht gelesen werden, Name, Version und Loader werden aus dem Dateinamen abgeleitet.',
        ],
        'scan' => [
            'running' => 'Hochgeladene Mods werden im Hintergrund gelesen …',
            'running_progress' => 'Hochgeladene Mods werden im Hintergrund gelesen (:current/:total) …',
        ],
        'actions' => [
            'update' => 'Aktualisieren',
            'up_to_date' => 'Aktuell',
            'link' => 'Mit Modrinth verknüpfen',
            'relink' => 'Modrinth-Verknüpfung ändern',
            'link_detected' => 'Alle gefundenen Mods verknüpfen',
            'unlink' => 'Modrinth-Verknüpfung entfernen',
            'remove_entry' => 'Eintrag entfernen',
            'delete' => 'Löschen',
            'rescan' => 'Erneut analysieren',
            'rescan_hint' => 'Alle Mods neu einlesen und auf Modrinth suchen',
        ],
        'link' => [
            'heading' => ':name mit Modrinth verknüpfen',
            'description' => 'Wähle das Modrinth-Projekt und die Version von :file. Die Datei bleibt unverändert und wird ab jetzt wie eine von Modrinth installierte Mod aktualisiert.',
            'exact_match' => 'Modrinth kennt genau diese Datei: :name :version.',
            'project' => 'Modrinth-Projekt',
            'project_helper' => 'Nach Namen suchen oder einen Modrinth-Link bzw. Slug einfügen.',
            'version' => 'Installierte Version',
            'version_helper' => 'Version laut Datei: :version',
            'unknown_version' => 'Unbekannte Version (jede Version als Aktualisierung anbieten)',
            'detected_description' => 'Verknüpft :count Mods, die Modrinth anhand ihrer exakten Datei erkannt hat. Mods, deren Projekt bereits mit einer anderen Datei verknüpft ist, werden übersprungen.',
        ],
        'update' => [
            'heading' => ':name aktualisieren',
            'description' => 'Ersetzt Version :old_version durch die ausgewählte Version.',
            'modpack_hint' => 'Diese Mod gehört zum installierten Modpack. Eine einzelne Aktualisierung kann das Modpack beschädigen, eine Aktualisierung des Modpacks nimmt sie nicht zurück.',
            'version' => 'Version',
            'version_helper' => 'Versionen passend zum Loader und zur Minecraft-Version dieses Servers.',
        ],
        'unlink' => [
            'heading' => 'Modrinth-Verknüpfung von :name entfernen',
            'description' => ':file bleibt auf dem Server, wird aber nicht mehr über Modrinth aktualisiert.',
            'missing_description' => ':file ist nicht mehr auf dem Server, der Eintrag wird aus der Liste der installierten Mods entfernt.',
        ],
        'delete' => [
            'heading' => ':name löschen',
            'description' => 'Dadurch wird :file dauerhaft aus dem Ordner mods gelöscht.',
            'modpack_hint' => 'Diese Mod gehört zum installierten Modpack, ohne sie funktioniert das Modpack eventuell nicht.',
        ],
        'notifications' => [
            'linked' => 'Mod verknüpft',
            'linked_body' => ':file ist jetzt mit :name verknüpft',
            'linked_detected' => ':count Mods mit Modrinth verknüpft',
            'link_failed' => 'Verknüpfen fehlgeschlagen',
            'unlinked' => 'Modrinth-Verknüpfung entfernt',
            'unlink_failed' => 'Entfernen der Verknüpfung fehlgeschlagen',
            'updated' => 'Mod aktualisiert',
            'updated_body' => ':name wurde auf Version :version aktualisiert',
            'update_failed' => 'Aktualisierung fehlgeschlagen',
            'deleted' => 'Mod gelöscht',
            'deleted_body' => ':file wurde gelöscht',
            'delete_failed' => 'Löschen fehlgeschlagen',
            'scan_queued' => 'Die Mods werden im Hintergrund neu eingelesen',
            'scan_running' => 'Die Mods werden bereits eingelesen',
        ],
    ],

    'errors' => [
        'already_linked' => ':name ist bereits mit :file verknüpft. Entferne zuerst diese Verknüpfung.',
        'no_download' => 'Die ausgewählte Version hat keine herunterladbare Datei.',
        'mod_not_found' => ':file ist nicht mehr im Ordner mods.',
        'project_not_found' => 'Das ausgewählte Projekt wurde auf Modrinth nicht gefunden.',
        'version_not_found' => 'Die ausgewählte Version wurde auf Modrinth nicht gefunden.',
        'invalid_path' => 'Ungültiger Dateiname: :path',
        'daemon_unreachable' => 'Die Node ist nicht erreichbar.',
    ],
];
