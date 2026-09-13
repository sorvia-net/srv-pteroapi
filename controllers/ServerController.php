<?php

namespace Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Sunucu listesi, detay, kaynak kullanimi, guc ve konsol komutu.
 *
 * Liste **tek cagrida** node, egg, sahip ve birincil allocation bilgisini
 * de veriyor. Stok API'de bunlar ayri uclarda; 30 sunuculu bir panelde
 * kontrol duzleminin acilisi yuzlerce istege cikiyordu.
 */
class ServerController extends Controller
{
    public function __construct(
        private DaemonServerRepository $sunucuDeposu,
        private DaemonPowerRepository $gucDeposu,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $sorgu = Server::query()->with(['node', 'egg', 'user', 'allocation']);

        if ($request->filled('node')) {
            $sorgu->where('node_id', (int) $request->query('node'));
        }
        if ($request->filled('search')) {
            $arama = '%' . $request->query('search') . '%';
            $sorgu->where(function ($q) use ($arama) {
                $q->where('name', 'like', $arama)->orWhere('uuid', 'like', $arama);
            });
        }

        $sunucular = $sorgu->orderBy('name')->get()->map(fn (Server $s) => $this->ozet($s));

        return new JsonResponse([
            'ok' => true,
            'count' => $sunucular->count(),
            'servers' => $sunucular->values(),
        ]);
    }

    public function show(Server $server): JsonResponse
    {
        $server->loadMissing(['node', 'egg', 'user', 'allocation', 'allocations', 'variables']);

        $detay = $this->ozet($server);
        $detay['limits'] = [
            'memory' => $server->memory,
            'swap' => $server->swap,
            'disk' => $server->disk,
            'io' => $server->io,
            'cpu' => $server->cpu,
            'threads' => $server->threads,
            'oom_disabled' => (bool) $server->oom_disabled,
        ];
        $detay['feature_limits'] = [
            'databases' => $server->database_limit,
            'allocations' => $server->allocation_limit,
            'backups' => $server->backup_limit,
        ];
        $detay['allocations'] = $server->allocations->map(fn ($a) => [
            'id' => $a->id,
            'ip' => $a->ip,
            'port' => $a->port,
            'alias' => $a->ip_alias,
            'primary' => $a->id === $server->allocation_id,
        ])->values();

        // Degisken degerleri sunucunun yapilandirmasi; sir tasiyabilirler
        // (RCON parolasi, lisans anahtari). Deger DEGIL, yalnizca hangi
        // degiskenlerin tanimli oldugu donuyor.
        $detay['variables'] = $server->variables->map(fn ($v) => [
            'name' => $v->name,
            'env_variable' => $v->env_variable,
            'has_value' => $v->server_value !== null && $v->server_value !== '',
        ])->values();

        return new JsonResponse(['ok' => true, 'server' => $detay]);
    }

    /** Wings'ten canli kaynak kullanimi. */
    public function usage(Server $server): JsonResponse
    {
        try {
            $veri = $this->sunucuDeposu->setServer($server)->getDetails();
        } catch (DaemonConnectionException $e) {
            return $this->daemonHatasi($server, $e);
        }

        return new JsonResponse(['ok' => true, 'usage' => $veri]);
    }

    public function power(Request $request, Server $server): JsonResponse
    {
        $eylem = (string) $request->input('signal', '');
        $izinli = ['start', 'stop', 'restart', 'kill'];

        if (!in_array($eylem, $izinli, true)) {
            return new JsonResponse([
                'ok' => false,
                'error' => [
                    'message' => 'Gecersiz guc sinyali.',
                    'how' => 'signal alani su degerlerden biri olmali: ' . implode(', ', $izinli),
                ],
            ], 422);
        }

        try {
            $this->gucDeposu->setServer($server)->send($eylem);
        } catch (DaemonConnectionException $e) {
            return $this->daemonHatasi($server, $e);
        }

        return new JsonResponse([
            'ok' => true,
            'signal' => $eylem,
            'server' => $server->uuidShort,
        ]);
    }

    public function command(Request $request, Server $server): JsonResponse
    {
        $komut = trim((string) $request->input('command', ''));
        if ($komut === '') {
            return new JsonResponse([
                'ok' => false,
                'error' => ['message' => 'Komut bos.', 'how' => 'command alanini doldurun.'],
            ], 422);
        }

        try {
            $this->sunucuDeposu->setServer($server)->send($komut);
        } catch (DaemonConnectionException $e) {
            // Sunucu kapaliyken komut gonderilemez; bu beklenen bir durum
            // ve "baglanti hatasi" demekten daha faydali.
            return new JsonResponse([
                'ok' => false,
                'error' => [
                    'message' => 'Komut gonderilemedi.',
                    'how' => 'Sunucu calisiyor mu? Kapali bir sunucunun konsoluna komut gitmez.',
                    'detail' => $e->getMessage(),
                ],
            ], 502);
        }

        return new JsonResponse(['ok' => true, 'sent' => $komut]);
    }

    /** Liste ve detayin ortak govdesi. */
    private function ozet(Server $s): array
    {
        return [
            'id' => $s->id,
            'uuid' => $s->uuid,
            'identifier' => $s->uuidShort,
            'name' => $s->name,
            'description' => $s->description,
            'suspended' => $s->isSuspended(),
            'status' => $s->status,
            'node' => $s->node ? ['id' => $s->node->id, 'name' => $s->node->name] : null,
            'egg' => $s->egg ? ['id' => $s->egg->id, 'name' => $s->egg->name] : null,
            'owner' => $s->user
                ? ['id' => $s->user->id, 'username' => $s->user->username, 'email' => $s->user->email]
                : null,
            'primary_allocation' => $s->allocation
                ? [
                    'ip' => $s->allocation->ip,
                    'port' => $s->allocation->port,
                    'alias' => $s->allocation->ip_alias,
                ]
                : null,
            'created_at' => optional($s->created_at)->toIso8601String(),
        ];
    }

    private function daemonHatasi(Server $s, DaemonConnectionException $e): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => [
                'message' => 'Dugum yaniti vermedi.',
                'how' => sprintf(
                    '%s dugumundeki wings servisi calisiyor mu, panelle arasindaki baglanti acik mi?',
                    $s->node->name ?? 'bilinmeyen'
                ),
                'detail' => $e->getMessage(),
            ],
        ], 502);
    }
}
