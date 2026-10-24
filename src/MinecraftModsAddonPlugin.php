<?php

namespace FreshDoktor\MinecraftModsAddon;

use Filament\Contracts\Plugin;
use Filament\Panel;
use FreshDoktor\MinecraftModsAddon\Livewire\InstalledModsBrowser;
use FreshDoktor\MinecraftModsAddon\ModrinthHub\ModrinthHub;
use Livewire\Livewire;

/**
 * Replaces the installed mods list of the minecraft-modrinth plugin with one that lists every jar in
 * the mods folder, including the ones uploaded by hand. minecraft-modrinth itself is never changed,
 * its mods page is shown as a section of the Modrinth page (see ModrinthHub).
 */
class MinecraftModsAddonPlugin implements Plugin
{
    public function getId(): string
    {
        return 'minecraft-mods-addon';
    }

    public function register(Panel $panel): void
    {
        // Without minecraft-modrinth there is no mods page to add to.
        if (!ModrinthHub::isModrinthPluginEnabled($panel->getId())) {
            return;
        }

        // Registered by name so Livewire finds the component again on updates, wherever it got embedded.
        Livewire::component('minecraft-mods-addon.installed-mods-browser', InstalledModsBrowser::class);

        ModrinthHub::registerInstalledModsBrowser(InstalledModsBrowser::class);

        // Hosted by this plugin unless another one already does.
        ModrinthHub::registerPage($panel);
    }

    public function boot(Panel $panel): void
    {
        // Runs once all plugins registered their pages, so the mods page of minecraft-modrinth is known by now.
        ModrinthHub::boot($panel);
    }
}
