<?php

namespace Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Pterodactyl\Models\Node;
use Pterodactyl\Repositories\Wings\DaemonConfigurationRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Dugumler ve kapasiteleri.
 *
 * Kapasite hesabi overallocate yuzdesini hesaba katiyor: panelde 8 GB RAM'li
 * bir dugum %50 overallocate ile 12 GB dagitabilir. Ham RAM'e bakip "dolu"
 * demek yanlis alarm uretirdi.
 *
 * Dugumun gercekten ayakta olup olmadigi ayri: veritabani "var" der, wings
 * cevap vermiyor olabilir. `online` alani bunu ayirt ediyor.
 */
class NodeController extends Controller
{
    public function __construct(private DaemonConfigurationRepository $depo)
    {
    }

    public function index(): JsonResponse
    {
        $dugumler = Node::query()->withCount('servers')->orderBy('name')->get();

        return new JsonResponse([
            'ok' => true,
            'count' => $dugumler->count(),
            'nodes' => $dugumler->map(fn (Node $n) => $this->ozet($n))->values(),
        ]);
    }

    public function show(Node $node): JsonResponse
    {
        $node->loadCount('servers');
        $detay = $this->ozet($node);

        // Canli durum: wings'e sorulur. Cevap vermezse sebebi yazilir.
        try {
            $yapilandirma = $this->depo->setNode($node)->getSystemInformation();
            $detay['online'] = true;
            $detay['system'] = $yapilandirma;
        } catch (DaemonConnectionException $e) {
            $detay['online'] = false;
            $detay['offline_reason'] = $e->getMessage();
        }

        $detay['allocations'] = [
            'total' => $node->allocations()->count(),
            'free' => $node->allocations()->whereNull('server_id')->count(),
        ];

        return new JsonResponse(['ok' => true, 'node' => $detay]);
    }

    private function ozet(Node $n): array
    {
        $ayrilanRam = (int) $n->servers()->sum('memory');
        $ayrilanDisk = (int) $n->servers()->sum('disk');

        return [
            'id' => $n->id,
            'uuid' => $n->uuid,
            'name' => $n->name,
            'fqdn' => $n->fqdn,
            'scheme' => $n->scheme,
            'maintenance' => (bool) $n->maintenance_mode,
            'public' => (bool) $n->public,
            'servers' => $n->servers_count ?? $n->servers()->count(),
            'memory' => [
                'allocated' => $ayrilanRam,
                'total' => (int) $n->memory,
                // -1 sinirsiz demek; kapasite hesaplanamaz, null doner.
                'capacity' => $n->memory_overallocate < 0
                    ? null
                    : (int) ($n->memory * (1 + $n->memory_overallocate / 100)),
                'overallocate' => $n->memory_overallocate,
            ],
            'disk' => [
                'allocated' => $ayrilanDisk,
                'total' => (int) $n->disk,
                'capacity' => $n->disk_overallocate < 0
                    ? null
                    : (int) ($n->disk * (1 + $n->disk_overallocate / 100)),
                'overallocate' => $n->disk_overallocate,
            ],
        ];
    }
}
