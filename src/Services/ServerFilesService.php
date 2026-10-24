<?php

namespace FreshDoktor\MinecraftModsAddon\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The files of a server this plugin works with: the mods folder through the daemon and the
 * installed list of the minecraft-modrinth plugin.
 */
class ServerFilesService
{
    // Installed list of the minecraft-modrinth plugin.
    public const MODRINTH_MODS_METADATA_FILE = 'mods/.modrinth-metadata.json';

    // Entries of the installed list added by minecraft-modpack-modrinth carry the modpack under this key.
    public const MODPACK_ENTRY_KEY = 'installed_by_modpack';

    // Installed modpack of minecraft-modpack-modrinth, if that plugin is used.
    public const MODPACK_METADATA_FILE = '.modrinth-modpack.json';

    // Seconds to wait for the daemon while it downloads a file for us.
    protected const DOWNLOAD_TIMEOUT = 120;

    protected const DEFAULT_DOWNLOAD_HOSTS = [
        'cdn.modrinth.com',
        'github.com',
        'raw.githubusercontent.com',
        'gitlab.com',
    ];

    public function isAllowedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $hosts = config('minecraft-mods-addon.allowed_download_hosts', self::DEFAULT_DOWNLOAD_HOSTS);

        return in_array(strtolower($parts['host']), array_map('strtolower', is_array($hosts) ? $hosts : self::DEFAULT_DOWNLOAD_HOSTS), true);
    }

    /**
     * Read a JSON file of the server, null if it doesn't exist or isn't valid JSON.
     *
     * @return array<mixed>|null
     */
    public function readJson(Server $server, string $path): ?array
    {
        try {
            $data = json_decode(app(DaemonFileRepository::class)->setServer($server)->getContent($path), true);
        } catch (FileNotFoundException) {
            return null;
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Change the installed list of the minecraft-modrinth plugin under the same lock it uses itself.
     *
     * $change gets the raw entries (null while the file doesn't exist) and returns the entries to
     * write, or null to leave the file as it is. Entries it doesn't know are passed along untouched.
     *
     * @param  callable(array<int, mixed>|null): (array<int, mixed>|null)  $change
     *
     * @throws Exception
     */
    public function updateModrinthModsMetadata(Server $server, callable $change): void
    {
        Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $change) {
            $fileRepository = app(DaemonFileRepository::class)->setServer($server);

            // Anything but a missing file must abort, rewriting the file from scratch would lose the user's mods.
            try {
                $content = $fileRepository->getContent(self::MODRINTH_MODS_METADATA_FILE);
            } catch (FileNotFoundException) {
                $content = null;
            }

            $metadata = $content === null ? null : json_decode($content, true);

            // json_decode() returns null for broken JSON too, which must not pass for a missing file.
            if ($content !== null && !is_array($metadata)) {
                throw new Exception(self::MODRINTH_MODS_METADATA_FILE.' is not valid JSON, leaving it alone');
            }

            $existing = $metadata === null ? null : (is_array($metadata['installed_mods'] ?? null) ? array_values($metadata['installed_mods']) : []);

            $installed = $change($existing);

            if ($installed === null) {
                return true;
            }

            $fileRepository->putContent(
                self::MODRINTH_MODS_METADATA_FILE,
                json_encode(['installed_mods' => array_values($installed)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            )->throw();

            return true;
        });
    }

    /**
     * Ask the daemon to download a file and wait until it is actually on disk.
     *
     * Without `foreground` the daemon downloads in the background, so the panel gets a success
     * response before the file exists and never hears about a failed download. The explicit file
     * name matters too: otherwise the daemon derives it from the URL.
     *
     * @return array<string, mixed> the daemon's directory entry of the downloaded file
     *
     * @throws Exception
     */
    public function downloadFile(Server $server, string $url, string $folder, string $filename): array
    {
        // If the name is already taken, finding it there afterwards says nothing about whether our download replaced it.
        $nameWasTaken = $this->findFile($server, $folder, $filename) !== null;

        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->getHttpClient()
                // The daemon holds the request open for the whole download, which easily outlives
                // the timeout used for the small file operations everything else here does.
                ->timeout(max((int) config('panel.guzzle.timeout'), self::DOWNLOAD_TIMEOUT))
                ->post("/api/servers/{$server->uuid}/files/pull", [
                    'url' => $url,
                    'root' => $folder === '' ? '/' : $folder,
                    'file_name' => $filename,
                    'foreground' => true,
                ])
                ->throw();
        } catch (Exception $exception) {
            // A slow download can outlive our request while still finishing on the node, but a
            // file appearing under a name that was free before is the only proof of that we get.
            if ($nameWasTaken) {
                throw $exception;
            }

            try {
                $landed = $this->findFile($server, $folder, $filename);
            } catch (Exception) {
                throw $exception;
            }

            return $landed ?? throw $exception;
        }

        return $this->findFile($server, $folder, $filename)
            ?? throw new Exception("Daemon reported success but $folder/$filename is missing after downloading $url");
    }

    /**
     * Turn a failed daemon call into something a user can act on, e.g. the daemon's own
     * "not enough disk space" instead of a bare exception.
     */
    public function describeDaemonError(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $error = $exception->response->json('error') ?? $exception->response->json('errors.0.detail');

            return trim("HTTP {$exception->response->status()}: ".(is_string($error) ? $error : $exception->response->reason()));
        }

        if ($exception instanceof ConnectionException) {
            return trans('minecraft-mods-addon::strings.errors.daemon_unreachable');
        }

        return $exception->getMessage();
    }

    /**
     * @param  array<int, string>  $paths  paths relative to the server root
     *
     * @throws Exception
     */
    public function deleteFiles(Server $server, array $paths): void
    {
        foreach (array_chunk(array_values($paths), 100) as $chunk) {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->deleteFiles('/', $chunk)
                ->throw();
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws Exception
     */
    public function findFile(Server $server, string $folder, string $filename): ?array
    {
        foreach ($this->listFolder($server, $folder) as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    public function listFolder(Server $server, string $folder): array
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory($folder === '' ? '/' : $folder);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                // The folder simply doesn't exist yet.
                return [];
            }

            throw $exception;
        }

        if (isset($files['error'])) {
            throw new Exception("Daemon returned an error while listing $folder: ".(is_string($files['error']) ? $files['error'] : 'unknown error'));
        }

        return $files;
    }
}
