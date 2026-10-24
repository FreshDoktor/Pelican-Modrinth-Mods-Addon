<?php

namespace FreshDoktor\MinecraftModsAddon\ModrinthHub;

use App\Models\Plugin;
use App\Models\Server;
use Filament\Panel;
use Livewire\Livewire;
use Throwable;

/**
 * One "Modrinth" page for mods, modpacks and datapacks, put together by plugins that don't depend
 * on each other.
 *
 * minecraft-modpack-modrinth, minecraft-datapack-modrinth and minecraft-mods-addon each ship an
 * identical copy of this folder, only the namespace and PLUGIN_ID differ, so each of them works on
 * its own. Every plugin registers its sections in the config under CONFIG_KEY, the first one to
 * register the page hosts it and the copies of the others stay unused.
 * The mods section wraps the mods page of the minecraft-modrinth plugin, which is never changed,
 * only taken out of the navigation.
 *
 * Keep the copies in sync. A section is only shown if its HUB_PROTOCOL matches PROTOCOL, so one
 * built for another version of the page is left out instead of breaking it.
 *
 * A section is a class with these static methods (duck typed, there is no shared interface):
 *  - getId(): string                                         key of its tab
 *  - getSort(): int                                          position of its tab
 *  - getLabel(): string                                      label of its tab
 *  - getNavigationLabel(): string                            name of the page while it is the only section
 *  - isAvailable(Server $server): bool
 *  - getInfoComponent(Server $server, bool $installedOnly): ?Component   shown above the tabs
 *  - getContentComponent(Server $server, bool $installedOnly): Component shown below the toggle
 *  - getHeaderActions(Server $server): array<Action>          must not use hidden(), the page hides them on other tabs
 *  - getRefreshEvents(): array<string>                       Livewire events after which the page renders again
 */
class ModrinthHub
{
    public const PROTOCOL = 1;

    // Plugin this copy belongs to, its lang files hold the strings of the page.
    public const PLUGIN_ID = 'minecraft-mods-addon';

    public const CONFIG_KEY = 'modrinth_hub';

    public const MODRINTH_PLUGIN_ID = 'minecraft-modrinth';

    public const MODRINTH_PLUGIN = 'Boy132\MinecraftModrinth\MinecraftModrinthPlugin';

    public const MODRINTH_MOD_PAGE = 'Boy132\MinecraftModrinth\Filament\Server\Pages\MinecraftModrinthModPage';

    /** @param  class-string  $section */
    public static function registerSection(string $section): void
    {
        config([self::CONFIG_KEY.'.sections.'.$section::getId() => $section]);
    }

    /**
     * Show $browser instead of the installed list of minecraft-modrinth while the mods section is
     * filtered to the installed ones. It needs the static methods make(Server): Component and
     * makeInfoGrid(Server): Component, and may have getRefreshEvents(): array<string> to have the
     * page render again after it changed something.
     *
     * @param  class-string  $browser
     */
    public static function registerInstalledModsBrowser(string $browser): void
    {
        config([self::CONFIG_KEY.'.installed_mods_browser' => $browser]);
    }

    /** @return class-string|null */
    public static function getInstalledModsBrowser(): ?string
    {
        $browser = config(self::CONFIG_KEY.'.installed_mods_browser');

        return is_string($browser) && class_exists($browser) ? $browser : null;
    }

    /**
     * Register the page, unless another plugin already did. Has to happen while the panel registers
     * its pages, so its route exists.
     */
    public static function registerPage(Panel $panel): void
    {
        $key = self::CONFIG_KEY.'.pages.'.$panel->getId();

        if (config($key) !== null) {
            return;
        }

        config([$key => ModrinthPage::class]);

        if (self::isModrinthPluginEnabled($panel->getId()) && class_exists(self::MODRINTH_MOD_PAGE)) {
            // Registered by name so Livewire finds the component again on updates.
            Livewire::component(self::PLUGIN_ID.'.modrinth-hub-mods-browser', ModsBrowser::class);

            self::registerSection(ModsSection::class);
        }

        $panel->pages([ModrinthPage::class]);
    }

    public static function isHost(Panel $panel): bool
    {
        return config(self::CONFIG_KEY.'.pages.'.$panel->getId()) === ModrinthPage::class;
    }

    /**
     * Take the mods page of minecraft-modrinth out of the panel's page list, its mods are a tab of
     * this page now. Its route stays registered, old links keep working. Runs once all plugins
     * registered their pages.
     *
     * Filament has no API to remove a page, hence reaching into the panel. Should that ever stop
     * working, both pages are listed, nothing breaks.
     */
    public static function boot(Panel $panel): void
    {
        if (!self::isHost($panel) || !isset(self::getSections()[ModsSection::getId()])) {
            return;
        }

        try {
            (function () {
                if (property_exists($this, 'pages') && is_array($this->pages)) {
                    $this->pages = array_values(array_filter($this->pages, fn ($page) => $page !== ModrinthHub::MODRINTH_MOD_PAGE));
                }
            })->call($panel);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array<string, class-string> every registered section speaking this protocol, in tab order */
    public static function getSections(): array
    {
        $sections = config(self::CONFIG_KEY.'.sections', []);

        $sections = array_filter(
            is_array($sections) ? $sections : [],
            fn ($section) => is_string($section) && class_exists($section) && defined("$section::HUB_PROTOCOL") && $section::HUB_PROTOCOL === self::PROTOCOL
        );

        uasort($sections, fn (string $a, string $b) => $a::getSort() <=> $b::getSort());

        return $sections;
    }

    /** @return array<string, class-string> the sections to show for $server, in tab order */
    public static function getAvailableSections(Server $server): array
    {
        return array_filter(self::getSections(), function (string $section) use ($server) {
            try {
                return $section::isAvailable($server);
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        });
    }

    /**
     * Whether the minecraft-modrinth plugin is enabled, on the given panel or regardless of the panel.
     *
     * The panel autoloads the classes of every plugin in the plugins folder, even of the ones that
     * aren't installed or are disabled, so their classes existing doesn't tell whether they are in use.
     */
    public static function isModrinthPluginEnabled(?string $panelId = null): bool
    {
        if (!class_exists(self::MODRINTH_PLUGIN)) {
            return false;
        }

        try {
            return Plugin::query()->find(self::MODRINTH_PLUGIN_ID)?->shouldLoad($panelId) ?? false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
