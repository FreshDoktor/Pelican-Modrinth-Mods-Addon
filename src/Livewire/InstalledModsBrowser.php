<?php

namespace FreshDoktor\MinecraftModsAddon\Livewire;

use App\Facades\Activity;
use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FreshDoktor\MinecraftModsAddon\Exceptions\ModException;
use FreshDoktor\MinecraftModsAddon\Services\InstalledModsService;
use FreshDoktor\MinecraftModsAddon\Services\ServerFilesService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The installed mods list: every jar in the mods folder, including the ones uploaded by hand.
 * Shown by the Modrinth page instead of the installed list of minecraft-modrinth, see
 * MinecraftModsAddonPlugin.
 *
 * Mods installed through minecraft-modrinth or a modpack are listed with their Modrinth project.
 * Uploaded mods are read in the background (see IdentifyModsJob) and can be linked to a Modrinth
 * project, after which they are updated like any other mod from Modrinth.
 */
class InstalledModsBrowser extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Dispatched once the mods folder changed, so the hosting page can refresh its info badges. */
    public const MODS_CHANGED_EVENT = 'installed-mods-changed';

    protected const UNKNOWN_VERSION = '__unknown__';

    #[Locked]
    public int $serverId;

    protected ?Server $server = null;

    /** @var array<string, array<string, mixed>>|null rows by key */
    protected ?array $mods = null;

    /** @var array<string, array<int, mixed>> compatible versions by project id */
    protected array $versionsCache = [];

    /** Loader of the server, '' if it has none Modrinth knows. */
    protected ?string $serverLoader = null;

    /** Same rule as the mods page of minecraft-modrinth. */
    public static function isAvailable(Server $server): bool
    {
        return in_array(ModrinthProjectType::Mod, ModrinthProjectType::fromServer($server), true);
    }

    /**
     * Events after which the hosting page renders again, see ModrinthHub::registerInstalledModsBrowser().
     *
     * @return array<string>
     */
    public static function getRefreshEvents(): array
    {
        return [self::MODS_CHANGED_EVENT];
    }

    public static function make(Server $server): LivewireComponent
    {
        return LivewireComponent::make(static::class, [
            'serverId' => $server->id,
        ])->key('modrinth-installed-mods');
    }

    /**
     * Minecraft version, loader and how many mods there are, the same place the other tabs show their info.
     */
    public static function makeInfoGrid(Server $server): Grid
    {
        return Grid::make(3)
            ->schema([
                TextEntry::make('minecraft_version')
                    ->label(trans('minecraft-mods-addon::strings.page.minecraft_version'))
                    ->state(fn () => MinecraftModrinth::getMinecraftVersion($server) ?? trans('minecraft-mods-addon::strings.page.unknown'))
                    ->badge(),
                TextEntry::make('loader')
                    ->label(trans('minecraft-mods-addon::strings.page.loader'))
                    ->state(fn () => MinecraftModrinth::getLoaderFromServer($server)['display_name'] ?? trans('minecraft-mods-addon::strings.page.unknown'))
                    ->icon(fn () => new HtmlString(MinecraftModrinth::getLoaderFromServer($server)['icon'] ?? ''))
                    ->badge(),
                TextEntry::make('installed_mods')
                    ->label(trans('minecraft-mods-addon::strings.mods.installed_count'))
                    ->state(function () use ($server) {
                        $service = app(InstalledModsService::class);

                        try {
                            $count = count($service->listModJars($server));
                        } catch (Exception $exception) {
                            report($exception);

                            // Don't pass off an unreachable daemon as an empty folder.
                            return trans('minecraft-mods-addon::strings.page.unknown');
                        }

                        $linked = count($service->getTrackedMods($server));

                        return trans('minecraft-mods-addon::strings.mods.installed_count_state', ['count' => $count, 'linked' => min($linked, $count)]);
                    })
                    ->badge(),
            ]);
    }

    public function mount(int $serverId): void
    {
        $this->serverId = $serverId;

        $this->authorizeAccess();

        // Queued before the first render, so the table already polls for the results.
        try {
            $this->service()->dispatchScanIfNeeded($this->getServer(), $this->getMods());
        } catch (Exception $exception) {
            // The table reports an unreachable daemon itself.
            report($exception);
        }
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Updates of this component don't pass through the page that rendered it, so check again
     * that the user may still access the server.
     */
    protected function authorizeAccess(): void
    {
        $server = $this->getServer();

        abort_unless(user()?->canAccessTenant($server) && static::isAvailable($server), 403);
    }

    protected function getServer(): Server
    {
        return $this->server ??= Server::findOrFail($this->serverId);
    }

    protected function service(): InstalledModsService
    {
        return app(InstalledModsService::class);
    }

    protected function userCan(string $permission): bool
    {
        return user()?->can($permission, $this->getServer()) ?? false;
    }

    /** @return array<string, array<string, mixed>> */
    protected function getMods(): array
    {
        return $this->mods ??= $this->service()->getInstalledMods($this->getServer());
    }

    /**
     * Drop everything read from the server so the next render reflects what is actually on disk.
     */
    protected function forgetMods(bool $folderChanged = false): void
    {
        $this->mods = null;
        $this->versionsCache = [];

        if ($folderChanged) {
            $this->dispatch(self::MODS_CHANGED_EVENT);
        }
    }

    /**
     * The row of the file as it is right now, the one the table was rendered from may be outdated.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     *
     * @throws ModException
     */
    protected function freshMod(array $record): array
    {
        $this->forgetMods();

        return $this->getMods()[$this->service()->getRowKey((string) ($record['filename'] ?? ''))]
            ?? throw new ModException(trans('minecraft-mods-addon::strings.errors.mod_not_found', ['file' => $record['filename'] ?? '']));
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!isset($this->versionsCache[$projectId])) {
            $this->versionsCache[$projectId] = MinecraftModrinth::getProjectVersions($projectId, $this->getServer());
        }

        return $this->versionsCache[$projectId];
    }

    /**
     * Fetch the versions of every linked mod on the current page at once instead of one by one.
     *
     * @param  array<int, string>  $projectIds
     */
    protected function primeVersionsCache(array $projectIds): void
    {
        $projectIds = array_values(array_diff(array_unique(array_filter($projectIds)), array_keys($this->versionsCache)));

        $service = MinecraftModrinth::getFacadeRoot();

        // Older versions of minecraft-modrinth can't fetch in bulk, getCachedVersions() then asks one by one.
        if (count($projectIds) < 2 || !method_exists($service, 'getProjectVersionsBulk')) {
            return;
        }

        $this->versionsCache += $service->getProjectVersionsBulk($projectIds, $this->getServer());
    }

    /** @param  array<string, mixed>  $record */
    protected function getLatestVersion(array $record): ?array
    {
        if (!$record['tracked'] || !empty($record['unavailable']) || $record['size'] === null) {
            return null;
        }

        return $this->getCachedVersions($record['project_id'])[0] ?? null;
    }

    /** @param  array<string, mixed>  $record */
    protected function hasUpdate(array $record): bool
    {
        $latest = $this->getLatestVersion($record);

        return $latest !== null && ($latest['id'] ?? null) !== $record['version_id'];
    }

    protected function isScanning(): bool
    {
        return $this->service()->isScanning($this->getServer());
    }

    /**
     * Whether the loaders the jar was made for rule out the loader of the server.
     *
     * @param  array<string, mixed>  $record
     */
    protected function isLoaderMismatch(array $record): bool
    {
        $serverLoader = $this->serverLoader ??= (MinecraftModrinth::getLoaderFromServer($this->getServer())['name'] ?? '');
        $loaders = $record['loaders'] ?? [];

        if (!$serverLoader || empty($loaders) || in_array($serverLoader, $loaders, true)) {
            return false;
        }

        // Quilt runs Fabric mods.
        return !($serverLoader === 'quilt' && in_array('fabric', $loaders, true));
    }

    /** @param  array<string, mixed>  $record */
    protected function getModrinthUrl(array $record): ?string
    {
        if (empty($record['project_slug']) || !empty($record['unavailable'])) {
            return null;
        }

        return 'https://modrinth.com/'.($record['project_type'] ?: 'mod').'/'.$record['project_slug'];
    }

    /**
     * Options for picking a project, labelled with title and slug so equally named mods can be told apart.
     *
     * @param  array<string, array<string, mixed>>  $projects
     * @return array<string, string>
     */
    protected function toProjectOptions(array $projects): array
    {
        $options = [];

        foreach ($projects as $id => $project) {
            $author = !empty($project['author']) ? ' · '.$project['author'] : '';
            $options[$id] = "{$project['title']} ({$project['slug']}){$author}";
        }

        return $options;
    }

    /**
     * Projects to offer before the user searched: the hash match, or a search for the mod's name.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    protected function getSuggestedProjects(array $record): array
    {
        $service = $this->service();

        if (!empty($record['match'])) {
            $project = $service->getProject($record['match']['project_id']);

            if ($project) {
                return $this->toProjectOptions([$project['id'] => $project]);
            }
        }

        $suggestions = [];
        foreach (array_filter([$record['mod_id'] ?? null, $record['title'] ?? null]) as $search) {
            $suggestions += $service->searchProjects((string) $search);

            if (count($suggestions) >= 5) {
                break;
            }
        }

        return $this->toProjectOptions(array_slice($suggestions, 0, 10, true));
    }

    /**
     * @return array<string, string>
     */
    protected function getVersionOptions(?string $projectId): array
    {
        if (!$projectId) {
            return [];
        }

        $options = [];

        foreach ($this->service()->getAllProjectVersions($projectId) as $version) {
            $loaders = implode(', ', array_map(fn ($loader) => Str::title((string) $loader), $version['loaders'] ?? []));
            $gameVersions = $version['game_versions'] ?? [];
            $minecraft = match (count($gameVersions)) {
                0 => '',
                1 => $gameVersions[0],
                default => reset($gameVersions).' – '.end($gameVersions),
            };

            $options[$version['id']] = trim(implode(' · ', array_filter([$version['version_number'] ?? $version['id'], $loaders, $minecraft])));
        }

        $options[self::UNKNOWN_VERSION] = trans('minecraft-mods-addon::strings.mods.link.unknown_version');

        return $options;
    }

    /** @param  array<string, mixed>  $record */
    protected function guessVersion(array $record, ?string $projectId): ?string
    {
        if (!$projectId) {
            return null;
        }

        return $this->service()->guessVersion($record, $projectId, $this->service()->getAllProjectVersions($projectId));
    }

    protected function notifyFailure(string $title, Exception $exception): void
    {
        if (!$exception instanceof ModException) {
            report($exception);
        }

        Notification::make()
            ->title($title)
            ->body($exception instanceof ModException ? $exception->getMessage() : app(ServerFilesService::class)->describeDaemonError($exception))
            ->danger()
            ->send();
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?array $filters, int $page, int $recordsPerPage) {
                try {
                    $mods = $this->getMods();
                } catch (Exception $exception) {
                    report($exception);

                    Notification::make()
                        ->title(trans('minecraft-mods-addon::strings.mods.list_failed'))
                        ->body(app(ServerFilesService::class)->describeDaemonError($exception))
                        ->danger()
                        ->send();

                    return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
                }

                $this->service()->dispatchScanIfNeeded($this->getServer(), $mods);

                if ($search) {
                    $search = strtolower($search);

                    $mods = array_filter($mods, fn (array $mod) => str_contains(strtolower($mod['title']), $search)
                        || str_contains(strtolower($mod['filename']), $search)
                        || str_contains(strtolower((string) $mod['mod_id']), $search)
                        || str_contains(strtolower((string) $mod['project_slug']), $search));
                }

                $status = $filters['status']['value'] ?? null;
                if (filled($status)) {
                    $mods = array_filter($mods, fn (array $mod) => $status === 'not_linked'
                        ? !$mod['tracked']
                        : $mod['status'] === $status);
                }

                $total = count($mods);
                $pageMods = array_slice($mods, ($page - 1) * $recordsPerPage, $recordsPerPage, true);

                $this->primeVersionsCache(array_values(array_filter(array_map(fn (array $mod) => $mod['tracked'] && $mod['size'] !== null ? $mod['project_id'] : null, $pageMods))));

                return new LengthAwarePaginator($pageMods, $total, $recordsPerPage, $page);
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            // Keep the query string apart from the table of the page this is embedded in.
            ->queryStringIdentifier('installed-mods')
            ->poll(fn () => $this->isScanning() ? '3s' : null)
            ->description(function () {
                $status = $this->service()->getScanStatus($this->getServer());

                if (!$status) {
                    return null;
                }

                return isset($status['current'], $status['total']) && $status['total'] > 0
                    ? trans('minecraft-mods-addon::strings.mods.scan.running_progress', ['current' => $status['current'], 'total' => $status['total']])
                    : trans('minecraft-mods-addon::strings.mods.scan.running');
            })
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->label(trans('minecraft-mods-addon::strings.mods.columns.name'))
                    ->weight('bold')
                    ->searchable()
                    ->description(function (array $record) {
                        $description = (string) ($record['description'] ?? '');
                        $description = strlen($description) > 100 ? substr($description, 0, 100).'...' : $description;

                        return new HtmlString(e($record['filename']).($description !== '' ? '<br>'.e($description) : ''));
                    })
                    ->wrap(),
                TextColumn::make('version')
                    ->label(trans('minecraft-mods-addon::strings.mods.columns.version'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->tooltip(fn (array $record) => $record['tracked'] && $record['version_id'] === ''
                        ? trans('minecraft-mods-addon::strings.mods.unknown_version_hint')
                        : null),
                TextColumn::make('loaders')
                    ->label(trans('minecraft-mods-addon::strings.page.loader'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => Str::title((string) $state))
                    ->color(fn (array $record) => $this->isLoaderMismatch($record) ? 'danger' : 'gray')
                    ->tooltip(fn (array $record) => $this->isLoaderMismatch($record) ? trans('minecraft-mods-addon::strings.mods.loader_mismatch') : null)
                    ->placeholder('—'),
                TextColumn::make('minecraft')
                    ->label(trans('minecraft-mods-addon::strings.page.minecraft_version'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(trans('minecraft-mods-addon::strings.mods.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => trans("minecraft-mods-addon::strings.mods.status.$state"))
                    ->tooltip(fn ($state, array $record) => match (true) {
                        ($record['environment'] ?? null) === 'client' => trans('minecraft-mods-addon::strings.mods.client_only'),
                        ($record['scan_error'] ?? null) !== null => trans("minecraft-mods-addon::strings.mods.scan_errors.{$record['scan_error']}"),
                        default => trans("minecraft-mods-addon::strings.mods.status_hints.$state"),
                    })
                    ->color(fn ($state) => match ($state) {
                        InstalledModsService::STATUS_LINKED => 'success',
                        InstalledModsService::STATUS_MODPACK => 'info',
                        InstalledModsService::STATUS_DETECTED => 'warning',
                        InstalledModsService::STATUS_MISSING => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn ($state, array $record) => match (true) {
                        ($record['environment'] ?? null) === 'client' => 'tabler-device-desktop',
                        $state === InstalledModsService::STATUS_LINKED => 'tabler-link',
                        $state === InstalledModsService::STATUS_MODPACK => 'tabler-box-multiple',
                        $state === InstalledModsService::STATUS_DETECTED => 'tabler-sparkles',
                        $state === InstalledModsService::STATUS_PENDING => 'tabler-loader-2',
                        $state === InstalledModsService::STATUS_MISSING => 'tabler-file-x',
                        default => 'tabler-upload',
                    }),
                TextColumn::make('size')
                    ->label(trans('minecraft-mods-addon::strings.mods.columns.size'))
                    ->formatStateUsing(fn ($state) => $state === null ? '' : $this->formatBytes((int) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('modified')
                    ->label(trans('minecraft-mods-addon::strings.table.columns.date_modified'))
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(trans('minecraft-mods-addon::strings.mods.columns.source'))
                    ->options(array_merge(
                        ['not_linked' => trans('minecraft-mods-addon::strings.mods.filter_not_linked')],
                        collect(InstalledModsService::STATUSES)->mapWithKeys(fn ($status) => [$status => trans("minecraft-mods-addon::strings.mods.status.$status")])->all(),
                    )),
            ])
            ->recordUrl(fn (array $record) => $record['tracked'] ? $this->getModrinthUrl($record) : null, true)
            // In the toolbar next to search and filters, so the table needs no header of its own.
            ->toolbarActions([
                Action::make('link_detected')
                    ->label(trans('minecraft-mods-addon::strings.mods.actions.link_detected'))
                    ->icon('tabler-link-plus')
                    ->color('warning')
                    ->visible(fn () => $this->userCan('file.update') && $this->countDetected() > 0)
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-mods-addon::strings.mods.actions.link_detected'))
                    ->modalDescription(fn () => trans('minecraft-mods-addon::strings.mods.link.detected_description', ['count' => $this->countDetected()]))
                    ->action(function () {
                        try {
                            $this->forgetMods();

                            $linked = $this->service()->linkDetectedMods($this->getServer(), array_values($this->getMods()));
                        } catch (Exception $exception) {
                            $this->forgetMods();
                            $this->notifyFailure(trans('minecraft-mods-addon::strings.mods.notifications.link_failed'), $exception);

                            return;
                        }

                        $this->forgetMods(true);

                        if ($linked > 0) {
                            Activity::event('server:mod.link-detected')
                                ->subject($this->getServer())
                                ->property('count', $linked)
                                ->log();
                        }

                        Notification::make()
                            ->title(trans('minecraft-mods-addon::strings.mods.notifications.linked_detected', ['count' => $linked]))
                            ->success()
                            ->send();
                    }),
                Action::make('rescan')
                    ->label(trans('minecraft-mods-addon::strings.mods.actions.rescan'))
                    ->icon('tabler-reload')
                    ->color('gray')
                    ->disabled(fn () => $this->isScanning())
                    ->tooltip(trans('minecraft-mods-addon::strings.mods.actions.rescan_hint'))
                    ->action(function () {
                        $server = $this->getServer();

                        $this->service()->forgetModInfo($server);
                        $this->forgetMods();

                        try {
                            $dispatched = $this->service()->dispatchScanIfNeeded($server, $this->getMods(), true);
                        } catch (Exception $exception) {
                            $this->notifyFailure(trans('minecraft-mods-addon::strings.mods.list_failed'), $exception);

                            return;
                        }

                        Notification::make()
                            ->title(trans($dispatched ? 'minecraft-mods-addon::strings.mods.notifications.scan_queued' : 'minecraft-mods-addon::strings.mods.notifications.scan_running'))
                            ->info()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('update')
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('minecraft-mods-addon::strings.mods.actions.update'))
                    ->visible(fn (array $record) => $this->userCan('file.update') && $this->hasUpdate($record))
                    ->modalHeading(fn (array $record) => trans('minecraft-mods-addon::strings.mods.update.heading', ['name' => $record['title']]))
                    ->modalDescription(fn (array $record) => new HtmlString(e(trans('minecraft-mods-addon::strings.mods.update.description', [
                        'old_version' => $record['version'] ?? trans('minecraft-mods-addon::strings.page.unknown'),
                    ])).($record['in_modpack'] ? '<br><br>'.e(trans('minecraft-mods-addon::strings.mods.update.modpack_hint')) : '')))
                    ->modalSubmitActionLabel(trans('minecraft-mods-addon::strings.mods.actions.update'))
                    ->fillForm(fn (array $record) => ['version_id' => $this->getLatestVersion($record)['id'] ?? null])
                    ->schema(fn (array $record) => [
                        Select::make('version_id')
                            ->label(trans('minecraft-mods-addon::strings.mods.update.version'))
                            ->options(function () use ($record) {
                                $options = [];

                                foreach ($this->getCachedVersions($record['project_id']) as $version) {
                                    // Going back to the installed version is no update.
                                    if (($version['id'] ?? null) === $record['version_id']) {
                                        break;
                                    }

                                    $options[$version['id']] = trim(($version['version_number'] ?? $version['id']).' · '.($version['version_type'] ?? ''), ' ·');
                                }

                                return $options;
                            })
                            ->helperText(trans('minecraft-mods-addon::strings.mods.update.version_helper'))
                            ->required()
                            ->searchable()
                            ->native(false),
                    ])
                    ->action(function (array $data, array $record) {
                        try {
                            $mod = $this->freshMod($record);

                            if (!$mod['tracked']) {
                                throw new ModException(trans('minecraft-mods-addon::strings.errors.mod_not_found', ['file' => $mod['filename']]));
                            }

                            $version = collect($this->getCachedVersions($mod['project_id']))->firstWhere('id', $data['version_id'] ?? null);

                            if (!$version) {
                                throw new ModException(trans('minecraft-mods-addon::strings.errors.version_not_found'));
                            }

                            $entry = collect($this->service()->getTrackedMods($this->getServer()))->first(fn ($entry) => strcasecmp($entry['filename'], $mod['filename']) === 0)
                                ?? throw new ModException(trans('minecraft-mods-addon::strings.errors.mod_not_found', ['file' => $mod['filename']]));

                            $this->service()->updateMod($this->getServer(), $entry, $version);
                        } catch (Exception $exception) {
                            $this->forgetMods(true);
                            $this->notifyFailure(trans('minecraft-mods-addon::strings.mods.notifications.update_failed'), $exception);

                            return;
                        }

                        $this->forgetMods(true);

                        Activity::event('server:mod.update')
                            ->subject($this->getServer())
                            ->property([
                                'name' => $record['title'],
                                'version' => $version['version_number'] ?? '',
                                'file' => $mod['filename'],
                            ])
                            ->log();

                        Notification::make()
                            ->title(trans('minecraft-mods-addon::strings.mods.notifications.updated'))
                            ->body(trans('minecraft-mods-addon::strings.mods.notifications.updated_body', [
                                'name' => $record['title'],
                                'version' => $version['version_number'] ?? '',
                            ]))
                            ->success()
                            ->send();
                    }),
                Action::make('up_to_date')
                    ->iconButton()
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('minecraft-mods-addon::strings.mods.actions.up_to_date'))
                    ->disabled()
                    ->visible(fn (array $record) => $record['tracked'] && $record['size'] !== null && !$this->hasUpdate($record)),
                Action::make('link')
                    ->iconButton()
                    ->icon(fn (array $record) => $record['tracked'] ? 'tabler-link' : 'tabler-link-plus')
                    ->color(fn (array $record) => $record['status'] === InstalledModsService::STATUS_DETECTED ? 'warning' : 'primary')
                    ->tooltip(fn (array $record) => trans($record['tracked'] ? 'minecraft-mods-addon::strings.mods.actions.relink' : 'minecraft-mods-addon::strings.mods.actions.link'))
                    ->visible(fn (array $record) => $this->userCan('file.update')
                        && $record['size'] !== null
                        && in_array($record['status'], [InstalledModsService::STATUS_LINKED, InstalledModsService::STATUS_DETECTED, InstalledModsService::STATUS_MANUAL, InstalledModsService::STATUS_MODPACK, InstalledModsService::STATUS_PENDING], true))
                    ->modalHeading(fn (array $record) => trans('minecraft-mods-addon::strings.mods.link.heading', ['name' => $record['title']]))
                    ->modalDescription(function (array $record) {
                        $lines = [trans('minecraft-mods-addon::strings.mods.link.description', ['file' => $record['filename']])];

                        if (!empty($record['match'])) {
                            $lines[] = trans('minecraft-mods-addon::strings.mods.link.exact_match', [
                                'name' => $record['match']['project_title'],
                                'version' => $record['match']['version_number'],
                            ]);
                        }

                        return new HtmlString(implode('<br><br>', array_map('e', $lines)));
                    })
                    ->modalSubmitActionLabel(trans('minecraft-mods-addon::strings.mods.actions.link'))
                    ->fillForm(function (array $record) {
                        $projectId = $record['tracked'] ? $record['project_id'] : ($record['match']['project_id'] ?? array_key_first($this->getSuggestedProjects($record)));

                        $versionId = $record['tracked'] && $record['project_id'] === $projectId
                            ? ($record['version_id'] !== '' ? $record['version_id'] : self::UNKNOWN_VERSION)
                            : $this->guessVersion($record, $projectId);

                        return ['project_id' => $projectId, 'version_id' => $versionId];
                    })
                    ->schema(fn (array $record) => [
                        Select::make('project_id')
                            ->label(trans('minecraft-mods-addon::strings.mods.link.project'))
                            ->helperText(trans('minecraft-mods-addon::strings.mods.link.project_helper'))
                            ->options(fn () => $this->getSuggestedProjects($record))
                            ->getSearchResultsUsing(fn (string $search) => $this->toProjectOptions($this->service()->searchProjects($search)))
                            ->getOptionLabelUsing(function ($value) {
                                $project = is_string($value) ? $this->service()->getProject($value) : null;

                                return $project ? $this->toProjectOptions([$project['id'] => $project])[$project['id']] : $value;
                            })
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($state, Set $set) => $set('version_id', $this->guessVersion($record, is_string($state) ? $state : null))),
                        Select::make('version_id')
                            ->label(trans('minecraft-mods-addon::strings.mods.link.version'))
                            ->helperText(trans('minecraft-mods-addon::strings.mods.link.version_helper', ['version' => $record['jar_version'] ?? trans('minecraft-mods-addon::strings.page.unknown')]))
                            ->options(fn (Get $get) => $this->getVersionOptions($get('project_id')))
                            ->searchable()
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function (array $data, array $record) {
                        try {
                            $mod = $this->freshMod($record);

                            $project = $this->service()->getProject((string) ($data['project_id'] ?? ''))
                                ?? throw new ModException(trans('minecraft-mods-addon::strings.errors.project_not_found'));

                            $version = null;
                            if (($data['version_id'] ?? null) !== self::UNKNOWN_VERSION) {
                                $version = collect($this->service()->getAllProjectVersions($project['id']))->firstWhere('id', $data['version_id'] ?? null)
                                    ?? throw new ModException(trans('minecraft-mods-addon::strings.errors.version_not_found'));
                            }

                            $this->service()->linkMod($this->getServer(), $mod['filename'], $project, $version);
                        } catch (Exception $exception) {
                            $this->forgetMods();
                            $this->notifyFailure(trans('minecraft-mods-addon::strings.mods.notifications.link_failed'), $exception);

                            return;
                        }

                        $this->forgetMods(true);

                        Activity::event('server:mod.link')
                            ->subject($this->getServer())
                            ->property([
                                'file' => $mod['filename'],
                                'name' => $project['title'],
                                'version' => $version['version_number'] ?? '',
                            ])
                            ->log();

                        Notification::make()
                            ->title(trans('minecraft-mods-addon::strings.mods.notifications.linked'))
                            ->body(trans('minecraft-mods-addon::strings.mods.notifications.linked_body', [
                                'file' => $mod['filename'],
                                'name' => $project['title'],
                            ]))
                            ->success()
                            ->send();
                    }),
                Action::make('unlink')
                    ->iconButton()
                    ->icon('tabler-unlink')
                    ->color('gray')
                    ->tooltip(fn (array $record) => trans($record['size'] === null ? 'minecraft-mods-addon::strings.mods.actions.remove_entry' : 'minecraft-mods-addon::strings.mods.actions.unlink'))
                    ->visible(fn (array $record) => $this->userCan('file.update') && $record['tracked'])
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('minecraft-mods-addon::strings.mods.unlink.heading', ['name' => $record['title']]))
                    ->modalDescription(fn (array $record) => trans($record['size'] === null ? 'minecraft-mods-addon::strings.mods.unlink.missing_description' : 'minecraft-mods-addon::strings.mods.unlink.description', ['file' => $record['filename']]))
                    ->action(function (array $record) {
                        try {
                            $filename = $this->service()->validateFilename((string) $record['filename']);

                            $this->service()->unlinkMod($this->getServer(), $filename);
                        } catch (Exception $exception) {
                            $this->forgetMods();
                            $this->notifyFailure(trans('minecraft-mods-addon::strings.mods.notifications.unlink_failed'), $exception);

                            return;
                        }

                        $this->forgetMods(true);

                        Activity::event('server:mod.unlink')
                            ->subject($this->getServer())
                            ->property([
                                'name' => $record['title'],
                                'file' => $filename,
                            ])
                            ->log();

                        Notification::make()
                            ->title(trans('minecraft-mods-addon::strings.mods.notifications.unlinked'))
                            ->success()
                            ->send();
                    }),
                Action::make('delete')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('minecraft-mods-addon::strings.mods.actions.delete'))
                    ->visible(fn (array $record) => $this->userCan('file.delete') && $record['size'] !== null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('minecraft-mods-addon::strings.mods.delete.heading', ['name' => $record['title']]))
                    ->modalDescription(fn (array $record) => new HtmlString(e(trans('minecraft-mods-addon::strings.mods.delete.description', ['file' => $record['filename']]))
                        .($record['in_modpack'] ? '<br><br>'.e(trans('minecraft-mods-addon::strings.mods.delete.modpack_hint')) : '')
                        .'<br><br><strong>'.e(trans('minecraft-mods-addon::strings.modals.stop_server_hint')).'</strong>'))
                    ->action(function (array $record) {
                        try {
                            $mod = $this->freshMod($record);

                            $this->service()->deleteMod($this->getServer(), $mod['filename'], $mod['size'] !== null);
                        } catch (Exception $exception) {
                            $this->forgetMods(true);
                            $this->notifyFailure(trans('minecraft-mods-addon::strings.mods.notifications.delete_failed'), $exception);

                            return;
                        }

                        $this->forgetMods(true);

                        Activity::event('server:mod.delete')
                            ->subject($this->getServer())
                            ->property('file', $mod['filename'])
                            ->log();

                        Notification::make()
                            ->title(trans('minecraft-mods-addon::strings.mods.notifications.deleted'))
                            ->body(trans('minecraft-mods-addon::strings.mods.notifications.deleted_body', ['file' => $mod['filename']]))
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateIcon('tabler-package-off')
            ->emptyStateHeading(trans('minecraft-mods-addon::strings.mods.empty_heading'))
            ->emptyStateDescription(trans('minecraft-mods-addon::strings.mods.empty_description'));
    }

    protected function countDetected(): int
    {
        try {
            return count(array_filter($this->getMods(), fn (array $mod) => $mod['status'] === InstalledModsService::STATUS_DETECTED));
        } catch (Exception) {
            return 0;
        }
    }

    protected function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => $bytes.' B',
            $bytes < 1_048_576 => round($bytes / 1024, 1).' KB',
            default => round($bytes / 1_048_576, 1).' MB',
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div class="flex flex-col gap-y-6">
                {{ $this->content }}

                <x-filament-actions::modals />
            </div>
        BLADE;
    }
}
