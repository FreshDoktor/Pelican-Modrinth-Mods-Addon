# Minecraft Mods Addon

An addon for the [Minecraft Modrinth](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) plugin by Boy132 for the [Pelican Panel](https://pelican.dev). It does nothing on its own, Minecraft Modrinth has to be installed and enabled.

The addon replaces the list of installed mods of Minecraft Modrinth with one that shows **every mod on the server**, including the ones uploaded by hand, and lets you link them to [Modrinth](https://modrinth.com) so they can be updated.

Minecraft Modrinth itself is not modified and can be updated as usual.

## Features

- **Every jar in the `mods` folder**: Lists not only the mods installed from Modrinth, but also the ones placed by a modpack or uploaded by hand
- **Background Analysis**: Reads name, version, loader, supported Minecraft version and client-only mods from the jar itself; mods made for another loader than the server's are highlighted
- **Link to Modrinth**: Uploaded mods can be linked to a Modrinth project, mods Modrinth recognises by their hash can be linked all at once; linked mods are updated like any other Modrinth mod
- **Manage each mod**: Update, link, unlink and delete mods right from the list
- **Multilingual**: Supports English and German translations

## Requirements

- Pelican Panel with plugin support
- The [Minecraft Modrinth](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) plugin by Boy132, installed and enabled (this is an addon for it)
- A running queue worker (see [Setup](#setup))

## Installation

Clone the repository into the `plugins` folder of your panel. The folder has to be named like the plugin id:

```bash
cd /var/www/pelican/plugins
git clone https://github.com/FreshDoktor/Pelican-Modrinth-Mods-Addon.git minecraft-mods-addon
```

Make sure Minecraft Modrinth is installed and enabled, then install and enable the plugin in the admin area of the panel under _Plugins_.

## Setup

The addon uses the same egg setup as Minecraft Modrinth: `modrinth_mods` in the _features_ of your egg (or `mods` together with the `minecraft` tag), and a tag matching the loader (e.g. `fabric`, `quilt`, `forge` or `neoforge`).

Uploaded mods are analysed in the background, so the panel's **queue worker must be running** (`pelican-queue` service, or `php artisan queue:work`).

## Usage

The mods of Minecraft Modrinth are shown on the _Modrinth_ page. With _Only show installed_ switched on, the list of this addon is shown:

| Source | Meaning |
| --- | --- |
| Modrinth | Linked to a Modrinth project, updates are offered |
| Modpack | Placed by the modpack installed with [Minecraft Modpack Modrinth](https://github.com/FreshDoktor/Pelican-Modrinth-Modpack-Installer) |
| Found on Modrinth | Uploaded by hand, Modrinth knows this exact file (by its SHA-1 hash) |
| Uploaded | Uploaded by hand, not found on Modrinth |
| Analysing | Not read yet |
| File missing | Listed as installed, but the jar is gone |

Uploaded mods are read by the queue worker: name, mod id, version, loader, supported Minecraft version and client-only mods are taken from the metadata inside the jar (`fabric.mod.json`, `quilt.mod.json`, `META-INF/mods.toml`, `META-INF/neoforge.mods.toml`, `mcmod.info`), falling back to the file name.

Any uploaded mod can be **linked to a Modrinth project** afterwards (search by name or paste a Modrinth link). Mods Modrinth recognised are pre-selected with their exact version and can be linked all at once. Linking adds the mod to the installed list of Minecraft Modrinth (`mods/.modrinth-metadata.json`), from then on it is updated like a mod installed from Modrinth. If the installed version is unknown, every compatible version is offered as an update.

### Together with the other Modrinth plugins

[Minecraft Modpack Modrinth](https://github.com/FreshDoktor/Pelican-Modrinth-Modpack-Installer) and [Minecraft Datapack Modrinth](https://github.com/FreshDoktor/Pelican-Minecraft-Datapack-Installer) add their tabs to the same _Modrinth_ page. Each of the plugins works on its own, any combination of them can be installed.

## Configuration

| Env | Default | Description |
| --- | --- | --- |
| `MINECRAFT_MODS_ADDON_MAX_SCAN_SIZE_MB` | `64` | Largest mod jar that is read to identify it, bigger ones are only judged by their file name |

What was read from uploaded mods is cached in the panel's cache, _Analyse again_ throws it away.

## License

Licensed under the [GNU General Public License v3.0](LICENSE).
