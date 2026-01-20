# Pelican Minecraft Manager

Easily download and install Minecraft cores, plugins, and mods directly from Modrinth within the Pelican Panel.

> [!WARNING]
> **DISCLAIMER:** This addon was created with "vibecoding" for personal use to have a quick and fast solution. I do not intend to take credit from the original creator. Use this addon **at your own risk**, without any guarantees and/or support.
Original plugin by [Boy132](https://github.com/Boy132). Repository available [here](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth).

## Features

### Core Management
- **Install & Reinstall Cores**: Easily install or reinstall Minecraft server cores (Paper and Velocity) directly from the panel.
- **Version Control**: View installed build versions and easily update to the latest available build.
- **Visual Feedback**: Clear status indicators for installed versions and build channels (Stable, Beta, Alpha).

### Plugin & Mod Management
- **Modrinth Integration**: Browse and search Modrinth's extensive library of plugins and mods.
- **Smart Filtering**: Automatically filters compatible plugins for your server's version.
- **"Show Incompatible" Toggle**: Option to bypass version filtering to view and download *any* plugin version (use with caution!).
- **One-Click Updates**: deeply integrated update system to keep your plugins current.
- **Optimized Performance**: Parallel update checking for fast loading of installed plugins lists.

## Setup

Add `modrinth_mods` or `modrinth_plugins` to the _features_ of your egg to enable the mod/plugins page.  
Also make sure your egg has the `minecraft` _tag_ and a _tag_ for the [mod loader](https://github.com/pelican-dev/plugins/blob/main/minecraft-modrinth/src/Enums/MinecraftLoader.php#L10-L16) (e.g., `paper` or `neoforge`).

## License

This project is licensed under the [GNU General Public License v3.0 (GPLv3)](https://www.gnu.org/licenses/gpl-3.0.html), consistent with the original plugin by Boy132.
