<?php

namespace FreshDoktor\MinecraftModsAddon\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use FreshDoktor\MinecraftModsAddon\Exceptions\ModException;
use FreshDoktor\MinecraftModsAddon\Jobs\IdentifyModsJob;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Everything in the mods folder, no matter how it got there: installed through minecraft-modrinth,
 * placed by a modpack, or uploaded by hand.
 *
 * Mods that were uploaded by hand are read by IdentifyModsJob, which takes the name, version and
 * loader from the metadata inside the jar and looks the file up on Modrinth by its hash. Linking such
 * a mod adds it to the installed list of minecraft-modrinth, from then on it is updated like any
 * other mod installed from Modrinth.
 *
 * @phpstan-import-type JarInfo from ModJarParser
 *
 * @phpstan-type ModFile array{name: string, size: int, modified: string}
 * @phpstan-type ModrinthMatch array{project_id: string, project_slug: string, project_title: string, icon_url: ?string, version_id: string, version_number: string}
 * @phpstan-type ModInfo array{size: int, modified: string, sha1: ?string, jar: JarInfo, scan_error: ?string, read_at: ?string, match: ModrinthMatch|null, checked_at: ?string, lookup_failed_at: ?string}
 * @phpstan-type TrackedMod array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, updated_at?: string, author?: string, installed_by_modpack?: string}
 */
class InstalledModsService
{
    public const MODS_FOLDER = 'mods';

    public const STATUS_LINKED = 'linked';

    public const STATUS_MODPACK = 'modpack';

    public const STATUS_DETECTED = 'detected';

    public const STATUS_MANUAL = 'manual';

    public const STATUS_PENDING = 'pending';

    public const STATUS_MISSING = 'missing';

    public const STATUSES = [
        self::STATUS_LINKED,
        self::STATUS_MODPACK,
        self::STATUS_DETECTED,
        self::STATUS_MANUAL,
        self::STATUS_PENDING,
        self::STATUS_MISSING,
    ];

    // A mod that matched nothing on Modrinth is looked up again after this many days, it may have been published since.
    protected const RECHECK_AFTER_DAYS = 7;

    protected const INFO_CACHE_DAYS = 60;

    // A scan that hasn't reported progress for this long is treated as dead, e.g. because no queue worker is running.
    protected const SCAN_STALE_MINUTES = 15;

    protected const RETRY_UNREADABLE_MINUTES = 60;

    protected const RETRY_LOOKUP_MINUTES = 30;

    protected const PROJECTS_CACHE_MINUTES = 30;

    protected const SEARCH_CACHE_MINUTES = 10;

    public function __construct(
        protected ServerFilesService $serverFiles,
        protected ModJarParser $parser,
    ) {}

    public function getParser(): ModJarParser
    {
        return $this->parser;
    }

    /**
     * Every jar right inside the mods folder, by file name.
     *
     * @return array<string, ModFile>
     *
     * @throws Exception
     */
    public function listModJars(Server $server): array
    {
        $jars = [];

        foreach ($this->serverFiles->listFolder($server, self::MODS_FOLDER) as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                continue;
            }

            // The daemon calls a file "file" and a folder "directory", symlinks are neither of our business.
            if (empty($entry['file']) || !empty($entry['directory']) || !empty($entry['symlink'])) {
                continue;
            }

            $name = $entry['name'];

            if (!$this->isValidFilename($name) || !str_ends_with(strtolower($name), '.jar')) {
                continue;
            }

            $jars[$name] = [
                'name' => $name,
                'size' => (int) ($entry['size'] ?? 0),
                'modified' => (string) ($entry['modified'] ?? ''),
            ];
        }

        ksort($jars, SORT_NATURAL | SORT_FLAG_CASE);

        return $jars;
    }

    public function isValidFilename(string $filename): bool
    {
        return $filename !== ''
            && $filename !== '.'
            && $filename !== '..'
            && !str_contains($filename, "\0")
            && !str_contains($filename, '/')
            && !str_contains($filename, '\\');
    }

    /**
     * @throws ModException
     */
    public function validateFilename(string $filename): string
    {
        if (!$this->isValidFilename($filename)) {
            throw new ModException(trans('minecraft-mods-addon::strings.errors.invalid_path', ['path' => $filename]));
        }

        return $filename;
    }

    /**
     * The installed list of minecraft-modrinth, validated the same way it does it.
     *
     * @return array<int, TrackedMod>
     */
    public function getTrackedMods(Server $server): array
    {
        try {
            $metadata = json_decode(app(DaemonFileRepository::class)->setServer($server)->getContent(ServerFilesService::MODRINTH_MODS_METADATA_FILE), true);
        } catch (FileNotFoundException) {
            return [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        $tracked = [];

        foreach (is_array($metadata['installed_mods'] ?? null) ? $metadata['installed_mods'] : [] as $entry) {
            if (!$this->isValidTrackedEntry($entry) || isset($tracked[$entry['project_id']])) {
                continue;
            }

            $tracked[$entry['project_id']] = $entry;
        }

        return array_values($tracked);
    }

    protected function isValidTrackedEntry(mixed $entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }

        foreach (['project_id', 'project_slug', 'project_title', 'version_id', 'version_number', 'filename', 'installed_at'] as $key) {
            if (!isset($entry[$key]) || !is_string($entry[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Files the modpack installed by minecraft-modpack-modrinth placed in the mods folder, by lower
     * case file name. Empty if that plugin isn't used.
     *
     * @return array<string, true>
     */
    public function getModpackFiles(Server $server): array
    {
        $modpack = $this->serverFiles->readJson($server, ServerFilesService::MODPACK_METADATA_FILE);

        $files = [];

        foreach (is_array($modpack['files'] ?? null) ? $modpack['files'] : [] as $path) {
            if (is_string($path) && preg_match('#^'.self::MODS_FOLDER.'/([^/]+)$#i', $path, $matches)) {
                $files[strtolower($matches[1])] = true;
            }
        }

        return $files;
    }

    protected function getModInfoCacheKey(Server|int $server): string
    {
        return 'minecraft_mods_addon:info:'.($server instanceof Server ? $server->id : $server);
    }

    /** @return array<string, ModInfo> scan results by file name */
    public function getModInfo(Server|int $server): array
    {
        $info = cache()->get($this->getModInfoCacheKey($server));

        return is_array($info) ? $info : [];
    }

    /** @param  array<string, ModInfo>  $info */
    protected function putModInfo(Server|int $server, array $info): void
    {
        cache()->put($this->getModInfoCacheKey($server), $info, now()->addDays(self::INFO_CACHE_DAYS));
    }

    public function forgetModInfo(Server $server): void
    {
        cache()->forget($this->getModInfoCacheKey($server));
    }

    /**
     * Whether the scan results still belong to the file, i.e. it wasn't replaced since.
     *
     * @param  ModInfo|null  $info
     * @param  ModFile  $file
     */
    public function isCurrent(?array $info, array $file): bool
    {
        return is_array($info)
            && ($info['size'] ?? null) === $file['size']
            && ($info['modified'] ?? null) === $file['modified'];
    }

    /**
     * Whether the jar has to be read (again): it is new, was replaced, or couldn't be read last time.
     *
     * @param  ModInfo|null  $info
     * @param  ModFile  $file
     */
    public function needsRead(?array $info, array $file): bool
    {
        if (!$this->isCurrent($info, $file)) {
            return true;
        }

        return ($info['scan_error'] ?? null) === 'unreadable' && $this->isOlderThan($info['read_at'] ?? null, now()->subMinutes(self::RETRY_UNREADABLE_MINUTES));
    }

    /**
     * Whether the jar should be looked up on Modrinth (again).
     *
     * @param  ModInfo|null  $info
     */
    public function needsLookup(?array $info): bool
    {
        if (!is_array($info) || empty($info['sha1']) || !empty($info['match'])) {
            return false;
        }

        // Modrinth didn't answer a moment ago, asking again right away won't help.
        if (!$this->isOlderThan($info['lookup_failed_at'] ?? null, now()->subMinutes(self::RETRY_LOOKUP_MINUTES))) {
            return false;
        }

        return $this->isOlderThan($info['checked_at'] ?? null, now()->subDays(self::RECHECK_AFTER_DAYS));
    }

    /** Whether $timestamp is missing, invalid or before $threshold. */
    protected function isOlderThan(mixed $timestamp, Carbon $threshold): bool
    {
        if (!is_string($timestamp) || $timestamp === '') {
            return true;
        }

        try {
            return Carbon::parse($timestamp)->lt($threshold);
        } catch (Exception) {
            return true;
        }
    }

    /**
     * Read every new or changed jar and look up the unknown ones on Modrinth by their hash.
     * Runs on the queue, see IdentifyModsJob.
     *
     * @param  callable(int $current, int $total): void|null  $progress
     *
     * @throws Exception
     */
    public function scan(Server $server, ?callable $progress = null): void
    {
        $files = $this->listModJars($server);
        $trackedNames = array_flip(array_map('strtolower', array_column($this->getTrackedMods($server), 'filename')));

        // Forget whatever is gone from the folder by now.
        $info = array_intersect_key($this->getModInfo($server), $files);

        $toRead = array_values(array_filter($files, fn (array $file) => $this->needsRead($info[$file['name']] ?? null, $file)));
        $maxBytes = (int) config('minecraft-mods-addon.max_mod_scan_size_mb', 64) * 1024 * 1024;
        $fileRepository = app(DaemonFileRepository::class)->setServer($server);

        $total = count($toRead);

        foreach ($toRead as $index => $file) {
            if ($progress) {
                $progress($index, $total);
            }

            $entry = [
                'size' => $file['size'],
                'modified' => $file['modified'],
                'sha1' => null,
                'jar' => $this->parser->fromFilename($file['name']),
                'scan_error' => null,
                'read_at' => now()->toIso8601String(),
                'match' => null,
                'checked_at' => null,
                'lookup_failed_at' => null,
            ];

            if ($file['size'] > $maxBytes) {
                $entry['scan_error'] = 'too_large';
            } else {
                try {
                    $bytes = $fileRepository->getContent(self::MODS_FOLDER.'/'.$file['name'], $maxBytes);

                    $entry['sha1'] = sha1($bytes);
                    $entry['jar'] = $this->parser->parse($bytes, $file['name']);

                    unset($bytes);
                } catch (Exception $exception) {
                    // Keep what the file name says, needsRead() tries again in a while.
                    $entry['scan_error'] = 'unreadable';

                    report($exception);
                }
            }

            $info[$file['name']] = $entry;

            // Let the page show results while the rest is still being read.
            if ($index % 10 === 9) {
                $this->putModInfo($server, $info);
            }
        }

        if ($progress) {
            $progress($total, $total);
        }

        // Mods already in the installed list are known, the rest is looked up by hash.
        $toLookup = [];
        foreach ($info as $name => $entry) {
            if (!isset($trackedNames[strtolower($name)]) && $this->needsLookup($entry)) {
                $toLookup[$name] = strtolower($entry['sha1']);
            }
        }

        if (!empty($toLookup)) {
            $now = now()->toIso8601String();

            try {
                $matches = $this->lookupHashes(array_values($toLookup));

                foreach ($toLookup as $name => $sha1) {
                    $info[$name]['match'] = $matches[$sha1] ?? null;
                    $info[$name]['checked_at'] = $now;
                    $info[$name]['lookup_failed_at'] = null;
                }
            } catch (Exception $exception) {
                report($exception);

                foreach ($toLookup as $name => $sha1) {
                    $info[$name]['lookup_failed_at'] = $now;
                }
            }
        }

        $this->putModInfo($server, $info);
    }

    /**
     * @param  array<int, string>  $hashes  sha1 hashes
     * @return array<string, ModrinthMatch> by hash
     *
     * @throws Exception
     */
    protected function lookupHashes(array $hashes): array
    {
        $versions = [];

        foreach (array_chunk(array_values(array_unique($hashes)), 500) as $chunk) {
            $response = Http::asJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->throw()
                ->post('https://api.modrinth.com/v2/version_files', [
                    'hashes' => $chunk,
                    'algorithm' => 'sha1',
                ])
                ->json();

            foreach (is_array($response) ? $response : [] as $hash => $version) {
                if (is_array($version) && isset($version['id'], $version['project_id'])) {
                    $versions[strtolower((string) $hash)] = $version;
                }
            }
        }

        $projects = $this->getProjects(array_column($versions, 'project_id'));

        $matches = [];
        foreach ($versions as $hash => $version) {
            $project = $projects[$version['project_id']] ?? null;

            // Only mods belong into the mods folder, a matching resource pack or data pack is no match.
            if (!$project || !in_array($project['project_type'] ?? 'mod', ['mod', 'plugin'], true)) {
                continue;
            }

            $matches[$hash] = [
                'project_id' => (string) $project['id'],
                'project_slug' => (string) $project['slug'],
                'project_title' => (string) $project['title'],
                'icon_url' => is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
                'version_id' => (string) $version['id'],
                'version_number' => (string) ($version['version_number'] ?? ''),
            ];
        }

        return $matches;
    }

    /**
     * Modrinth projects by id, cached. $complete tells whether Modrinth answered for every id, so
     * a missing project really is gone and not just unreachable.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<string, mixed>>
     */
    public function getProjects(array $projectIds, ?bool &$complete = null): array
    {
        $complete = true;

        $projectIds = array_values(array_unique(array_filter($projectIds, fn ($id) => is_string($id) && $id !== '')));

        $projects = [];
        $missing = [];

        foreach ($projectIds as $projectId) {
            $cached = cache()->get("minecraft_mods_addon:project:$projectId");

            if (is_array($cached)) {
                $projects[$projectId] = $cached;
            } else {
                $missing[] = $projectId;
            }
        }

        foreach (array_chunk($missing, 100) as $chunk) {
            try {
                $response = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/projects', ['ids' => json_encode($chunk, JSON_THROW_ON_ERROR)])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                $complete = false;

                continue;
            }

            foreach (is_array($response) ? $response : [] as $project) {
                if (!is_array($project) || !isset($project['id'], $project['slug'], $project['title'])) {
                    continue;
                }

                $projects[$project['id']] = $project;

                cache()->put("minecraft_mods_addon:project:{$project['id']}", $project, now()->addMinutes(self::PROJECTS_CACHE_MINUTES));
            }
        }

        return $projects;
    }

    /**
     * A single project by id or slug, null if Modrinth doesn't know it.
     *
     * @return array<string, mixed>|null
     */
    public function getProject(string $idOrSlug): ?array
    {
        $idOrSlug = trim($idOrSlug);

        if ($idOrSlug === '' || !preg_match('/^[\w.!@$()`*-]+$/', $idOrSlug)) {
            return null;
        }

        $cached = cache()->get("minecraft_mods_addon:project:$idOrSlug");

        if (is_array($cached)) {
            return $cached;
        }

        // Most searches aren't a slug, don't ask Modrinth about them on every keystroke.
        if ($cached === false) {
            return null;
        }

        try {
            $response = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->get('https://api.modrinth.com/v2/project/'.rawurlencode($idOrSlug));
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        $project = $response->json();

        if (!is_array($project) || !isset($project['id'], $project['slug'], $project['title'])) {
            if ($response->status() === 404) {
                cache()->put("minecraft_mods_addon:project:$idOrSlug", false, now()->addMinutes(self::SEARCH_CACHE_MINUTES));
            }

            return null;
        }

        cache()->put("minecraft_mods_addon:project:$idOrSlug", $project, now()->addMinutes(self::PROJECTS_CACHE_MINUTES));

        return $project;
    }

    /**
     * Search Modrinth for mods to link a file to. A Modrinth link, slug or project id is resolved
     * directly and listed first.
     *
     * @return array<string, array<string, mixed>> projects by id
     */
    public function searchProjects(string $search): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $results = [];

        $direct = preg_match('#modrinth\.com/[a-z]+/([^/?\#\s]+)#i', $search, $matches) ? $matches[1] : (str_contains($search, ' ') ? null : $search);

        if ($direct !== null && ($project = $this->getProject($direct))) {
            $project['project_id'] = $project['id'];
            $results[$project['id']] = $project;
        }

        $key = 'minecraft_mods_addon:search:'.md5(strtolower($search));
        $hits = cache()->get($key);

        if (!is_array($hits)) {
            try {
                $hits = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/search', [
                        'query' => $search,
                        'limit' => 20,
                        'facets' => '[["project_type:mod","project_type:plugin"]]',
                    ])
                    ->json('hits');

                $hits = is_array($hits) ? $hits : [];

                cache()->put($key, $hits, now()->addMinutes(self::SEARCH_CACHE_MINUTES));
            } catch (Exception $exception) {
                report($exception);

                $hits = [];
            }
        }

        foreach ($hits as $hit) {
            if (is_array($hit) && isset($hit['project_id'], $hit['title'], $hit['slug'])) {
                $results[$hit['project_id']] ??= $hit;
            }
        }

        return $results;
    }

    /**
     * Every version of a project, regardless of the server's loader and Minecraft version: the
     * uploaded file may well be one that doesn't match them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllProjectVersions(string $projectId): array
    {
        if ($projectId === '') {
            return [];
        }

        $key = "minecraft_mods_addon:versions_all:$projectId";
        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $versions = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/project/'.rawurlencode($projectId).'/version')
                ->json();
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        $versions = array_values(array_filter(is_array($versions) ? $versions : [], fn ($version) => is_array($version) && isset($version['id'])));
        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        cache()->put($key, $versions, now()->addMinutes(self::SEARCH_CACHE_MINUTES));

        return $versions;
    }

    /**
     * Pick the version of $projectId that most likely is the uploaded file.
     *
     * @param  array<string, mixed>  $mod  a row of getInstalledMods()
     * @param  array<int, array<string, mixed>>  $versions
     */
    public function guessVersion(array $mod, string $projectId, array $versions): ?string
    {
        if (($mod['match']['project_id'] ?? null) === $projectId) {
            return $mod['match']['version_id'];
        }

        // Most uploads are the very file Modrinth hands out, just downloaded by hand.
        foreach ($versions as $version) {
            foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $file) {
                if (is_array($file) && ($file['filename'] ?? null) === $mod['filename']) {
                    return $version['id'];
                }
            }
        }

        $installedVersion = strtolower((string) ($mod['jar_version'] ?? ''));

        if ($installedVersion === '') {
            return null;
        }

        $candidates = array_filter($versions, fn ($version) => strtolower((string) ($version['version_number'] ?? '')) === $installedVersion);

        if (empty($candidates)) {
            $candidates = array_filter($versions, fn ($version) => str_contains(strtolower((string) ($version['version_number'] ?? '')), $installedVersion));
        }

        // Prefer the build for the loader the jar was made for.
        foreach ($candidates as $version) {
            if (array_intersect($mod['loaders'] ?? [], $version['loaders'] ?? [])) {
                return $version['id'];
            }
        }

        return $candidates ? reset($candidates)['id'] : null;
    }

    /**
     * Rows for the installed mods list: every jar in the mods folder plus the entries of the
     * installed list whose file is gone.
     *
     * @return array<string, array<string, mixed>> by row key
     *
     * @throws Exception
     */
    public function getInstalledMods(Server $server): array
    {
        $files = $this->listModJars($server);
        $info = $this->getModInfo($server);
        $modpackFiles = $this->getModpackFiles($server);

        $tracked = [];
        foreach ($this->getTrackedMods($server) as $entry) {
            $tracked[strtolower($entry['filename'])] ??= $entry;
        }

        $rows = [];

        foreach ($files as $name => $file) {
            $entry = $tracked[strtolower($name)] ?? null;
            unset($tracked[strtolower($name)]);

            $rows[$this->getRowKey($name)] = $this->makeRow($file, $info[$name] ?? null, $entry, isset($modpackFiles[strtolower($name)]));
        }

        foreach ($tracked as $entry) {
            if (!$this->isValidFilename($entry['filename'])) {
                continue;
            }

            $rows[$this->getRowKey($entry['filename'])] = $this->makeRow(null, null, $entry, false);
        }

        $projects = $this->getProjects(array_values(array_filter(array_map(fn (array $row) => $row['tracked'] ? $row['project_id'] : null, $rows))), $complete);

        foreach ($rows as $key => $row) {
            if (!$row['tracked']) {
                continue;
            }

            $project = $projects[$row['project_id']] ?? null;

            if ($project) {
                $rows[$key]['title'] = $project['title'];
                $rows[$key]['project_slug'] = $project['slug'];
                $rows[$key]['description'] = $project['description'] ?? $row['description'];
                $rows[$key]['icon_url'] = $project['icon_url'] ?? null;
                $rows[$key]['project_type'] = $project['project_type'] ?? 'mod';
            } elseif ($complete) {
                // Modrinth answered but doesn't know the project anymore.
                $rows[$key]['unavailable'] = true;
            }
        }

        uasort($rows, fn (array $a, array $b) => strcasecmp($a['title'], $b['title']) ?: strcasecmp($a['filename'], $b['filename']));

        return $rows;
    }

    public function getRowKey(string $filename): string
    {
        return 'mod-'.md5($filename);
    }

    /**
     * @param  ModFile|null  $file
     * @param  ModInfo|null  $info
     * @param  TrackedMod|null  $entry
     * @return array<string, mixed>
     */
    protected function makeRow(?array $file, ?array $info, ?array $entry, bool $inModpack): array
    {
        $filename = $file['name'] ?? $entry['filename'];

        // Results of a scan of an older file of the same name say nothing about this one.
        if ($file && !$this->isCurrent($info, $file)) {
            $info = null;
        }

        $jar = $info['jar'] ?? $this->parser->fromFilename($filename);
        $match = $entry ? null : ($info['match'] ?? null);

        $status = match (true) {
            $file === null => self::STATUS_MISSING,
            $entry !== null && !empty($entry[ServerFilesService::MODPACK_ENTRY_KEY]) => self::STATUS_MODPACK,
            $entry !== null => self::STATUS_LINKED,
            $match !== null => self::STATUS_DETECTED,
            $inModpack && $info !== null => self::STATUS_MODPACK,
            $info === null => self::STATUS_PENDING,
            default => self::STATUS_MANUAL,
        };

        $versionNumber = $entry && $entry['version_id'] !== '' ? $entry['version_number'] : null;

        return [
            'key' => $this->getRowKey($filename),
            'filename' => $filename,
            'size' => $file['size'] ?? null,
            'modified' => $file['modified'] ?? null,
            'title' => $entry['project_title'] ?? $match['project_title'] ?? $jar['name'] ?? $this->parser->cleanFilename($filename),
            'description' => $jar['description'] ?? null,
            'icon_url' => $match['icon_url'] ?? null,
            'mod_id' => $jar['mod_id'] ?? null,
            'version' => $versionNumber ?? $jar['version'] ?? null,
            'jar_version' => $jar['version'] ?? null,
            'loaders' => $jar['loaders'] ?? [],
            'minecraft' => $jar['minecraft'] ?? null,
            'environment' => $jar['environment'] ?? null,
            'authors' => $jar['authors'] ?? [],
            'status' => $status,
            'tracked' => $entry !== null,
            'in_modpack' => $inModpack || !empty($entry[ServerFilesService::MODPACK_ENTRY_KEY]),
            'project_id' => $entry['project_id'] ?? $match['project_id'] ?? null,
            'project_slug' => $entry['project_slug'] ?? $match['project_slug'] ?? null,
            'project_type' => 'mod',
            'version_id' => $entry['version_id'] ?? null,
            'version_number' => $entry['version_number'] ?? null,
            'author' => $entry['author'] ?? null,
            'match' => $match,
            'scan_error' => $info['scan_error'] ?? null,
            'unavailable' => false,
        ];
    }

    /**
     * Add an uploaded mod to the installed list, so it can be updated like a mod installed from Modrinth.
     *
     * @param  array<string, mixed>  $project  Modrinth project
     * @param  array<string, mixed>|null  $version  Modrinth version the file is, null if unknown
     *
     * @throws Exception
     */
    public function linkMod(Server $server, string $filename, array $project, ?array $version): void
    {
        $this->validateFilename($filename);

        if ($version !== null && ($version['project_id'] ?? null) !== $project['id']) {
            throw new ModException(trans('minecraft-mods-addon::strings.errors.version_not_found'));
        }

        $entry = [
            'project_id' => (string) $project['id'],
            'project_slug' => (string) $project['slug'],
            'project_title' => (string) $project['title'],
            // Without a known version every version counts as an update, which is what the user wants then.
            'version_id' => $version !== null ? (string) $version['id'] : '',
            'version_number' => $version !== null ? (string) ($version['version_number'] ?? '') : '',
            'filename' => $filename,
            'installed_at' => now()->toIso8601String(),
        ];

        $this->saveTrackedMods($server, [$entry], false);
    }

    /**
     * Link every uploaded mod Modrinth recognised by its hash.
     *
     * @param  array<int, array<string, mixed>>  $rows  rows of getInstalledMods()
     * @return int how many got linked
     *
     * @throws Exception
     */
    public function linkDetectedMods(Server $server, array $rows): int
    {
        $entries = [];
        $now = now()->toIso8601String();

        foreach ($rows as $row) {
            $match = $row['match'] ?? null;

            if ($row['status'] !== self::STATUS_DETECTED || !is_array($match) || isset($entries[$match['project_id']])) {
                continue;
            }

            $entries[$match['project_id']] = [
                'project_id' => $match['project_id'],
                'project_slug' => $match['project_slug'],
                'project_title' => $match['project_title'],
                'version_id' => $match['version_id'],
                'version_number' => $match['version_number'],
                'filename' => $row['filename'],
                'installed_at' => $now,
            ];
        }

        if (empty($entries)) {
            return 0;
        }

        return $this->saveTrackedMods($server, array_values($entries), true);
    }

    /**
     * Put entries into the installed list, replacing whatever was listed for the same file.
     *
     * @param  array<int, array<string, string>>  $entries
     * @param  bool  $skipTaken  skip entries whose project is listed for another file, instead of failing
     * @return int how many entries were saved
     *
     * @throws Exception
     */
    protected function saveTrackedMods(Server $server, array $entries, bool $skipTaken): int
    {
        $saved = 0;

        $this->serverFiles->updateModrinthModsMetadata($server, function (?array $existing) use ($entries, $skipTaken, &$saved) {
            $installed = $existing ?? [];

            foreach ($entries as $entry) {
                $taken = collect($installed)->first(fn ($other) => is_array($other)
                    && ($other['project_id'] ?? null) === $entry['project_id']
                    && strcasecmp((string) ($other['filename'] ?? ''), $entry['filename']) !== 0);

                // minecraft-modrinth only knows one file per project.
                if ($taken) {
                    if ($skipTaken) {
                        continue;
                    }

                    throw new ModException(trans('minecraft-mods-addon::strings.errors.already_linked', [
                        'name' => $entry['project_title'],
                        'file' => (string) ($taken['filename'] ?? ''),
                    ]));
                }

                $replaced = false;
                foreach ($installed as $index => $other) {
                    if (is_array($other) && strcasecmp((string) ($other['filename'] ?? ''), $entry['filename']) === 0) {
                        if ($replaced) {
                            unset($installed[$index]);

                            continue;
                        }

                        $installed[$index] = $entry;
                        $replaced = true;
                    }
                }

                if (!$replaced) {
                    $installed[] = $entry;
                }

                $saved++;
            }

            return $saved > 0 ? array_values($installed) : null;
        });

        return $saved;
    }

    /**
     * Take a mod off the installed list, its file stays.
     *
     * @throws Exception
     */
    public function unlinkMod(Server $server, string $filename): void
    {
        $this->serverFiles->updateModrinthModsMetadata($server, function (?array $existing) use ($filename) {
            if ($existing === null) {
                return null;
            }

            return array_values(array_filter($existing, fn ($entry) => !is_array($entry) || strcasecmp((string) ($entry['filename'] ?? ''), $filename) !== 0));
        });
    }

    /**
     * Replace a linked mod with another version of it, keeping the installed list in line with
     * what is on disk even when something fails halfway.
     *
     * @param  TrackedMod  $entry
     * @param  array<string, mixed>  $version  Modrinth version
     *
     * @throws Exception
     */
    public function updateMod(Server $server, array $entry, array $version): void
    {
        if (($version['project_id'] ?? null) !== $entry['project_id']) {
            throw new ModException(trans('minecraft-mods-addon::strings.errors.version_not_found'));
        }

        $file = $this->getPrimaryFile(is_array($version['files'] ?? null) ? $version['files'] : []);

        if (!$file || !$this->serverFiles->isAllowedDownloadUrl($file['url'])) {
            throw new ModException(trans('minecraft-mods-addon::strings.errors.no_download'));
        }

        $oldFilename = $this->validateFilename($entry['filename']);
        $newFilename = $this->validateFilename($file['filename']);

        $this->serverFiles->downloadFile($server, $file['url'], self::MODS_FOLDER, $newFilename);

        $updated = $entry;
        $updated['version_id'] = (string) $version['id'];
        $updated['version_number'] = (string) ($version['version_number'] ?? '');
        $updated['filename'] = $newFilename;
        $updated['updated_at'] = now()->toIso8601String();
        // Updated by hand, a later update of the modpack must not take it back.
        unset($updated[ServerFilesService::MODPACK_ENTRY_KEY]);

        try {
            $this->replaceTrackedMod($server, $oldFilename, $updated);
        } catch (Exception $exception) {
            if (strcasecmp($oldFilename, $newFilename) !== 0) {
                $this->deleteQuietly($server, $newFilename);
            }

            throw $exception;
        }

        if (strcasecmp($oldFilename, $newFilename) === 0) {
            return;
        }

        try {
            $this->serverFiles->deleteFiles($server, [self::MODS_FOLDER.'/'.$oldFilename]);
        } catch (Exception $exception) {
            // Two versions of the same mod crash the server, so rather go back to the old one.
            $this->deleteQuietly($server, $newFilename);

            try {
                $this->replaceTrackedMod($server, $newFilename, $entry);
            } catch (Exception $restoreException) {
                report($restoreException);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     *
     * @throws Exception
     */
    protected function replaceTrackedMod(Server $server, string $filename, array $entry): void
    {
        $this->serverFiles->updateModrinthModsMetadata($server, function (?array $existing) use ($filename, $entry) {
            $installed = [];
            $replaced = false;

            foreach ($existing ?? [] as $other) {
                $isSame = is_array($other) && (
                    strcasecmp((string) ($other['filename'] ?? ''), $filename) === 0
                    || ($other['project_id'] ?? null) === $entry['project_id']
                );

                if (!$isSame) {
                    $installed[] = $other;
                } elseif (!$replaced) {
                    // Same place in the list, so the row doesn't jump around.
                    $installed[] = $entry;
                    $replaced = true;
                }
            }

            if (!$replaced) {
                $installed[] = $entry;
            }

            return $installed;
        });
    }

    /**
     * Delete a mod file and its entry of the installed list.
     *
     * @throws Exception
     */
    public function deleteMod(Server $server, string $filename, bool $fileExists = true): void
    {
        $this->validateFilename($filename);

        if ($fileExists) {
            $this->serverFiles->deleteFiles($server, [self::MODS_FOLDER.'/'.$filename]);
        }

        $this->unlinkMod($server, $filename);
    }

    protected function deleteQuietly(Server $server, string $filename): void
    {
        try {
            $this->serverFiles->deleteFiles($server, [self::MODS_FOLDER.'/'.$filename]);
        } catch (Exception $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<int, mixed>  $files
     * @return array{url: string, filename: string}|null
     */
    public function getPrimaryFile(array $files): ?array
    {
        $files = array_values(array_filter($files, fn ($file) => is_array($file) && is_string($file['url'] ?? null) && is_string($file['filename'] ?? null)));

        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return $files[0] ?? null;
    }

    protected function getScanCacheKey(Server|int $server): string
    {
        return 'minecraft_mods_addon:scan:'.($server instanceof Server ? $server->id : $server);
    }

    /** @return array{status: string, current: ?int, total: ?int, updated_at: int}|null */
    public function getScanStatus(Server|int $server): ?array
    {
        $status = cache()->get($this->getScanCacheKey($server));

        if (!is_array($status) || ($status['updated_at'] ?? 0) < now()->subMinutes(self::SCAN_STALE_MINUTES)->timestamp) {
            return null;
        }

        return $status;
    }

    public function isScanning(Server|int $server): bool
    {
        return $this->getScanStatus($server) !== null;
    }

    public function updateScanStatus(Server|int $server, string $status, ?int $current = null, ?int $total = null): void
    {
        cache()->put($this->getScanCacheKey($server), [
            'status' => $status,
            'current' => $current,
            'total' => $total,
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(self::SCAN_STALE_MINUTES));
    }

    public function finishScan(Server|int $server): void
    {
        cache()->forget($this->getScanCacheKey($server));
    }

    /**
     * Queue a scan if any jar is new, changed or due for another look on Modrinth.
     *
     * @param  array<string, array<string, mixed>>  $rows  rows of getInstalledMods()
     */
    public function dispatchScanIfNeeded(Server $server, array $rows, bool $force = false): bool
    {
        if (!$force) {
            $info = $this->getModInfo($server);

            $hasWork = collect($rows)->contains(function (array $row) use ($info) {
                if ($row['size'] === null) {
                    return false;
                }

                $rowInfo = $info[$row['filename']] ?? null;

                return $this->needsRead($rowInfo, ['name' => $row['filename'], 'size' => $row['size'], 'modified' => (string) $row['modified']])
                    || (!$row['tracked'] && $this->needsLookup($rowInfo));
            });

            if (!$hasWork) {
                return false;
            }
        }

        $status = [
            'status' => 'queued',
            'current' => null,
            'total' => null,
            'updated_at' => now()->timestamp,
        ];

        // Atomic, so refreshing the page while a scan is queued doesn't queue another one.
        if (!Cache::add($this->getScanCacheKey($server), $status, now()->addMinutes(self::SCAN_STALE_MINUTES))) {
            // A stale entry of a dead scan blocks until it expires, unless it is replaced right away.
            if ($this->isScanning($server)) {
                return false;
            }

            cache()->put($this->getScanCacheKey($server), $status, now()->addMinutes(self::SCAN_STALE_MINUTES));
        }

        try {
            IdentifyModsJob::dispatch($server->id);
        } catch (Exception $exception) {
            report($exception);

            $this->finishScan($server);

            return false;
        }

        return true;
    }
}
