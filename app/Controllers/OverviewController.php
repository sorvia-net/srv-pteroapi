<?php

namespace Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary as Blueprint;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Panelin tek bakista ozeti.
 *
 * Stok API ayni bilgiyi almak icin dort ayri uc ve sayfalama gerektiriyor;
 * kontrol duzlemi her acilista bunu yapmasin diye tek cagriya indirildi.
 */
class OverviewController extends Controller
{
    public function __construct(private Blueprint $blueprint)
    {
    }

    /** Eklenti ayakta mi ve hangi surum. */
    public function ping(): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'extension' => 'srvpteroapi',
            // Surum conf.yml'den geliyor. Sabit yazmak, eklenti guncellenince
            // kontrol duzlemine yanlis surum bildirmek demekti.
            'version' => $this->blueprint->extensionConfig('srvpteroapi')['info']['version'] ?? 'bilinmiyor',
            'panel' => config('app.version', 'bilinmiyor'),
            'time' => now()->toIso8601String(),
        ]);
    }

    public function overview(): JsonResponse
    {
        $nodes = Node::query()->get(['id', 'name', 'memory', 'disk', 'memory_overallocate', 'disk_overallocate']);

        $sunucuSayisi = Server::query()->count();
        $askidaki = Server::query()->whereNotNull('status')->count();

        // Dugum basina ayrilmis kaynak: sunucularin limit toplami.
        $ayrilan = Server::query()
            ->selectRaw('node_id, sum(memory) as memory, sum(disk) as disk, count(*) as adet')
            ->groupBy('node_id')
            ->get()
            ->keyBy('node_id');

        $dugumler = $nodes->map(function (Node $n) use ($ayrilan) {
            $k = $ayrilan->get($n->id);
            $kullanilanRam = (int) ($k->memory ?? 0);
            $kullanilanDisk = (int) ($k->disk ?? 0);

            // Overallocate yuzdesi kapasiteyi buyutuyor; -1 sinirsiz demek.
            $ramKapasite = $n->memory_overallocate < 0
                ? null
                : (int) ($n->memory * (1 + $n->memory_overallocate / 100));
            $diskKapasite = $n->disk_overallocate < 0
                ? null
                : (int) ($n->disk * (1 + $n->disk_overallocate / 100));

            return [
                'id' => $n->id,
                'name' => $n->name,
                'servers' => (int) ($k->adet ?? 0),
                'memory' => [
                    'allocated' => $kullanilanRam,
                    'total' => (int) $n->memory,
                    'capacity' => $ramKapasite,
                ],
                'disk' => [
                    'allocated' => $kullanilanDisk,
                    'total' => (int) $n->disk,
                    'capacity' => $diskKapasite,
                ],
            ];
        })->values();

        return new JsonResponse([
            'ok' => true,
            'counts' => [
                'nodes' => $nodes->count(),
                'servers' => $sunucuSayisi,
                'servers_suspended_or_installing' => $askidaki,
                'users' => User::query()->count(),
                'eggs' => Egg::query()->count(),
                'allocations' => Allocation::query()->count(),
                'allocations_free' => Allocation::query()->whereNull('server_id')->count(),
            ],
            'nodes' => $dugumler,
        ]);
    }
}
