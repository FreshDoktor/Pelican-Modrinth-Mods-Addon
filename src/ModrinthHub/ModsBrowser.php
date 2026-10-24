<?php

namespace FreshDoktor\MinecraftModsAddon\ModrinthHub;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Boy132\MinecraftModrinth\Filament\Server\Pages\MinecraftModrinthModPage;
use Exception;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * The mods page of minecraft-modrinth embedded into the Modrinth page: browsing, installing and
 * updating mods is inherited, only its info, tabs and header are left out, the Modrinth page shows
 * those itself.
 *
 * Only loaded while minecraft-modrinth is enabled, see ModrinthHub::registerPage().
 */
class ModsBrowser extends MinecraftModrinthModPage
{
    /** The list of minecraft-modrinth to show: 'all' or 'installed'. Set by the Modrinth page's toggle. */
    #[Locked]
    public ?string $activeTab = null;

    public static function makeComponent(bool $installedOnly): LivewireComponent
    {
        return LivewireComponent::make(static::class, [
            'installedOnly' => $installedOnly,
        ])->key('modrinth-hub-mods-'.($installedOnly ? 'installed' : 'all'));
    }

    /**
     * Minecraft version, loader and number of mods, as the mods page of minecraft-modrinth shows them.
     */
    public static function makeInfoGrid(Server $server): Grid
    {
        return Grid::make(3)
            ->schema([
                TextEntry::make('minecraft_version')
                    ->label(trans(ModrinthHub::PLUGIN_ID.'::hub.minecraft_version'))
                    ->state(fn () => MinecraftModrinth::getMinecraftVersion($server) ?? trans('minecraft-modrinth::strings.page.unknown'))
                    ->badge(),
                TextEntry::make('loader')
                    ->label(trans(ModrinthHub::PLUGIN_ID.'::hub.loader'))
                    ->state(fn () => MinecraftModrinth::getLoaderFromServer($server)['display_name'] ?? trans('minecraft-modrinth::strings.page.unknown'))
                    ->icon(fn () => new HtmlString(MinecraftModrinth::getLoaderFromServer($server)['icon'] ?? ''))
                    ->badge(),
                TextEntry::make('installed_mods')
                    ->label(fn () => trans('minecraft-modrinth::strings.page.installed', ['type' => ModrinthProjectType::Mod->getLabel()]))
                    ->state(function () use ($server) {
                        try {
                            return collect(MinecraftModrinth::listFolder($server, ModrinthProjectType::Mod->getFolder()))
                                ->filter(fn ($file) => ($file['mime'] ?? null) === 'application/jar' || str($file['name'] ?? '')->lower()->endsWith('.jar'))
                                ->count();
                        } catch (Exception $exception) {
                            report($exception);

                            // Don't pass off an unreachable daemon as an empty folder.
                            return trans('minecraft-modrinth::strings.page.unknown');
                        }
                    })
                    ->badge(),
            ]);
    }

    public function mount(bool $installedOnly = false): void
    {
        $this->activeTab = $installedOnly ? 'installed' : 'all';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    /** Just the list, not the page around it. */
    public function render(): View
    {
        return view()->file(plugin_path(ModrinthHub::PLUGIN_ID, 'resources/views/modrinth-hub/mods-browser.blade.php'));
    }
}
