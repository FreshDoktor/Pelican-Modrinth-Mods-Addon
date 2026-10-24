<?php

namespace FreshDoktor\MinecraftModsAddon\ModrinthHub;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * The Modrinth page: one tab per section, a toggle to only show what is installed, and the list
 * of the active section. See ModrinthHub.
 *
 * Lives outside of the discovered Pages folder because it is registered by ModrinthHub, only by
 * the plugin that hosts it.
 */
class ModrinthPage extends Page
{
    use BlockAccessInConflict {
        canAccess as canAccessWhenNotInConflict;
    }
    use HasTabs;

    /** Only list what is installed on the server. */
    public bool $installedOnly = false;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'modrinth';

    protected static ?int $navigationSort = 30;

    /** @return array<string, class-string> */
    protected static function getAvailableSections(): array
    {
        $server = Filament::getTenant();

        return $server instanceof Server ? ModrinthHub::getAvailableSections($server) : [];
    }

    public static function canAccess(): bool
    {
        return static::canAccessWhenNotInConflict() && !empty(static::getAvailableSections());
    }

    /**
     * "Modrinth" while it combines several sections, otherwise named after its only section,
     * e.g. "Modrinth Datapacks".
     */
    public static function getNavigationLabel(): string
    {
        $sections = static::getAvailableSections();

        if (count($sections) === 1) {
            return reset($sections)::getNavigationLabel();
        }

        return trans(ModrinthHub::PLUGIN_ID.'::hub.title');
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        if (!isset(static::getAvailableSections()[$this->activeTab ?? ''])) {
            $this->activeTab = null;
        }

        $this->loadDefaultActiveTab();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return array_map(fn (string $section) => Tab::make($section::getLabel()), static::getAvailableSections());
    }

    /** The tabs only switch the section, there is no table on this page whose pagination needs a reset. */
    public function updatedActiveTab(): void {}

    /** @return class-string|null */
    protected function getActiveSection(): ?string
    {
        $sections = static::getAvailableSections();

        return $sections[$this->activeTab ?? ''] ?? (reset($sections) ?: null);
    }

    /**
     * Render again once a section reports a change, so the info badges are up to date.
     *
     * @return array<string, string>
     */
    protected function getListeners()
    {
        $listeners = parent::getListeners();

        foreach (ModrinthHub::getSections() as $section) {
            foreach ($section::getRefreshEvents() as $event) {
                $listeners[$event] = '$refresh';
            }
        }

        return $listeners;
    }

    /**
     * The actions of every section, each one only visible on the tab of its section. Header actions
     * are cached before a tab change is applied, so they can't be picked by the active tab.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server) {
            return [];
        }

        $actions = [];

        foreach (static::getAvailableSections() as $section) {
            foreach ($section::getHeaderActions($server) as $action) {
                $actions[] = $action->hidden(fn () => $this->getActiveSection() !== $section);
            }
        }

        return $actions;
    }

    public function content(Schema $schema): Schema
    {
        $server = Filament::getTenant();
        $section = $this->getActiveSection();

        if (!$server instanceof Server || !$section) {
            return $schema->components([]);
        }

        return $schema
            ->components(array_values(array_filter([
                $section::getInfoComponent($server, $this->installedOnly),
                $this->getTabsContentComponent()
                    ->hidden(count(static::getAvailableSections()) < 2),
                Toggle::make('installedOnly')
                    ->label(trans(ModrinthHub::PLUGIN_ID.'::hub.installed_only'))
                    ->live(),
                $section::getContentComponent($server, $this->installedOnly),
            ])));
    }
}
