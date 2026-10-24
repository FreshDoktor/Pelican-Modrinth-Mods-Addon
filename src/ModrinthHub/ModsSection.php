<?php

namespace FreshDoktor\MinecraftModsAddon\ModrinthHub;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;

/**
 * The mods of the minecraft-modrinth plugin as a section of the Modrinth page. Only registered,
 * and so only loaded, while that plugin is enabled.
 *
 * Filtered to the installed mods it shows the browser registered through
 * ModrinthHub::registerInstalledModsBrowser(), if there is one, otherwise the installed list of
 * minecraft-modrinth.
 */
class ModsSection
{
    public const HUB_PROTOCOL = ModrinthHub::PROTOCOL;

    public static function getId(): string
    {
        return 'mods';
    }

    public static function getSort(): int
    {
        return 10;
    }

    public static function getLabel(): string
    {
        return trans(ModrinthHub::PLUGIN_ID.'::hub.mods');
    }

    public static function getNavigationLabel(): string
    {
        return trans(ModrinthHub::PLUGIN_ID.'::hub.mods_navigation');
    }

    public static function isAvailable(Server $server): bool
    {
        return in_array(ModrinthProjectType::Mod, ModrinthProjectType::fromServer($server), true);
    }

    public static function getInfoComponent(Server $server, bool $installedOnly): ?Component
    {
        $browser = $installedOnly ? ModrinthHub::getInstalledModsBrowser() : null;

        return $browser ? $browser::makeInfoGrid($server) : ModsBrowser::makeInfoGrid($server);
    }

    public static function getContentComponent(Server $server, bool $installedOnly): Component
    {
        $browser = $installedOnly ? ModrinthHub::getInstalledModsBrowser() : null;

        return $browser ? $browser::make($server) : ModsBrowser::make($installedOnly);
    }

    /** @return array<Action> */
    public static function getHeaderActions(Server $server): array
    {
        $folder = ModrinthProjectType::Mod->getFolder();

        return [
            Action::make('mods_open_folder')
                ->tooltip(trans('minecraft-modrinth::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
        ];
    }

    /** @return array<string> */
    public static function getRefreshEvents(): array
    {
        $browser = ModrinthHub::getInstalledModsBrowser();

        return $browser && method_exists($browser, 'getRefreshEvents') ? $browser::getRefreshEvents() : [];
    }
}
