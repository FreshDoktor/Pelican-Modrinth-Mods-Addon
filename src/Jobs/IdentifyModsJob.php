<?php

namespace FreshDoktor\MinecraftModsAddon\Jobs;

use App\Models\Server;
use FreshDoktor\MinecraftModsAddon\Services\InstalledModsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Reads the mods of a server that weren't installed from Modrinth: name, version and loader from
 * the metadata inside each jar, and whether Modrinth knows the file by its hash.
 *
 * Runs on the queue because it reads every new jar off the daemon, which takes far too long for
 * a page load. The installed mods tab queues it and shows the results once they are in.
 */
class IdentifyModsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $serverId) {}

    public function handle(InstalledModsService $service): void
    {
        $server = Server::find($this->serverId);

        if (!$server) {
            $service->finishScan($this->serverId);

            return;
        }

        try {
            $service->updateScanStatus($server, 'running');

            $service->scan($server, fn (int $current, int $total) => $service->updateScanStatus($server, 'running', $current, $total));
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $service->finishScan($server);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(InstalledModsService::class)->finishScan($this->serverId);
    }
}
