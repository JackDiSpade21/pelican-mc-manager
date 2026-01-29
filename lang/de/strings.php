<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Mods',
    'minecraft_plugins' => 'Minecraft Plugins',

    'settings' => [
        'latest_minecraft_version' => 'Neueste Minecraft-Version',
        'settings_saved' => 'Einstellungen gespeichert',
    ],

    'page' => [
        'core_management' => 'Kern-Verwaltung',
        'open_folder' => ':folder-Ordner öffnen',
        'minecraft_version' => 'Minecraft-Version',
        'loader' => 'Loader',
        'installed' => 'Installiert :type',
        'installed_date' => 'Installiert',
        'installed_badge' => 'Installiert',
        'unknown' => 'Unbekannt',
        'not_installed' => 'Nicht installiert',
        'build' => 'Build',
        'release_date' => 'Veröffentlichungsdatum',
        'changes' => 'Änderungen',
        'installed_build' => 'Installierter Build',
        'none' => 'Keine',
        'version_selection' => 'Versionsauswahl',
        'major_version' => 'Hauptversion',
        'minor_version' => 'Nebenversion',
        'show_incompatible' => 'Inkompatible anzeigen',
        'view_installed' => 'Installierte anzeigen',
        'browse_modrinth' => 'Modrinth durchsuchen',
    ],

    'table' => [
        'columns' => [
            'title' => 'Titel',
            'author' => 'Autor',
            'downloads' => 'Downloads',
            'date_modified' => 'Geändert',
            'version' => 'Version',
        ],
    ],

    'version' => [
        'type' => 'Typ',
        'downloads' => 'Downloads',
        'published' => 'Veröffentlicht',
        'changelog' => 'Änderungsprotokoll',
        'no_file_found' => 'Keine Datei gefunden',
    ],

    'actions' => [
        'download' => 'Herunterladen',
        'update_to_latest' => 'Auf neueste Version aktualisieren',
        'install' => 'Installieren',
        'reinstall' => 'Neu installieren',
        'update' => 'Aktualisieren',
        'update_all' => 'Alle aktualisieren',
        'delete' => 'Löschen',
    ],

    'modals' => [
        'install_core' => 'Kern installieren',
        'confirm_reinstall' => 'Möchten Sie diese Kern-Version wirklich neu installieren? Der Server wird neu gestartet.',
        'confirm_install' => 'Möchten Sie diese Kern-Version wirklich installieren? Der Server wird neu gestartet.',
    ],

    'notifications' => [
        'download_started' => 'Download gestartet',
        'download_failed' => 'Download konnte nicht gestartet werden',
        'download_url_not_found' => 'Download-URL nicht gefunden',
        'core_installed' => 'Kern installiert',
        'all_plugins_updated' => 'Alle Plugins aktualisiert',
        'plugin_updated' => 'Plugin aktualisiert',
        'plugin_deleted' => 'Plugin gelöscht',
        'error_deleting_plugin' => 'Fehler beim Löschen des Plugins',
        'no_builds_found' => 'Keine Builds gefunden',
        'cannot_determine_download_url' => 'Download-URL kann nicht ermittelt werden',
        'updated_to_build' => 'Auf Build :build aktualisiert',
        'download_not_found_for_latest_build' => 'Download für neuesten Build nicht gefunden',
    ],
];
