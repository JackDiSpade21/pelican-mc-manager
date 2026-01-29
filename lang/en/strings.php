<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Mods',
    'minecraft_plugins' => 'Minecraft Plugins',

    'settings' => [
        'latest_minecraft_version' => 'Latest Minecraft Version',
        'settings_saved' => 'Settings saved',
    ],

    'page' => [
        'core_management' => 'Core Management',
        'open_folder' => 'Open :folder folder',
        'minecraft_version' => 'Minecraft Version',
        'loader' => 'Loader',
        'installed' => 'Installed :type',
        'installed_badge' => 'Installed',
        'unknown' => 'Unknown',
        'not_installed' => 'Not installed',
        'build' => 'Build',
        'release_date' => 'Release Date',
        'changes' => 'Changes',
        'installed_build' => 'Installed Build',
        'none' => 'None',
        'version_selection' => 'Version Selection',
        'major_version' => 'Major Version',
        'minor_version' => 'Minor Version',
        'show_incompatible' => 'Show Incompatible',
        'view_installed' => 'View Installed',
        'browse_modrinth' => 'Browse Modrinth',
    ],

    'table' => [
        'columns' => [
            'title' => 'Title',
            'author' => 'Author',
            'downloads' => 'Downloads',
            'date_modified' => 'Modified',
            'version' => 'Version',
            'installed_date' => 'Installed',
        ],
    ],

    'version' => [
        'type' => 'Type',
        'downloads' => 'Downloads',
        'published' => 'Published',
        'changelog' => 'Changelog',
        'no_file_found' => 'No file found',
    ],

    'actions' => [
        'download' => 'Download',
        'update_to_latest' => 'Update to latest',
        'install' => 'Install',
        'reinstall' => 'Reinstall',
        'update' => 'Update',
        'update_all' => 'Update All',
        'delete' => 'Delete',
    ],

    'modals' => [
        'install_core' => 'Install Core',
        'confirm_reinstall' => 'Are you sure you want to reinstall this core version? The server will be restarted.',
        'confirm_install' => 'Are you sure you want to install this core version? The server will be restarted.',
    ],

    'notifications' => [
        'download_started' => 'Download started',
        'download_failed' => 'Download could not be started',
        'download_url_not_found' => 'Download URL not found',
        'core_installed' => 'Core installed',
        'all_plugins_updated' => 'All plugins updated',
        'plugin_updated' => 'Plugin updated',
        'plugin_deleted' => 'Plugin deleted',
        'error_deleting_plugin' => 'Error deleting plugin',
        'no_builds_found' => 'No builds found',
        'cannot_determine_download_url' => 'Cannot determine download URL',
        'updated_to_build' => 'Updated to build :build',
        'download_not_found_for_latest_build' => 'Download not found for latest build',
    ],
];
