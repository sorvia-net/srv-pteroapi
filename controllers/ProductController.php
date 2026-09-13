<?php

namespace Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary as Blueprint;

/**
 * Kurulu Sorvia urunlerinin tespiti.
 *
 * "Hangi sunucuda hangi urunum var" sorusunun cevabi bugun hicbir yerde
 * yok: satis kaydi sorvia.net'te, kurulum sunucuda, ikisi birbirini
 * bilmiyor. Bu uc o baglantiyi kuruyor.
 *
 * **Tespit dosya adina bakarak yapiliyor, tahmin degil olcum:**
 *   · Minecraft  → plugins/ altindaki srv-*.jar, rxy-*.jar
 *   · FiveM      → resources/ altindaki srv-*, rxy-* dizinleri
 *
 * Surum, dosya adindaki `-1.2.3` kalibindan okunuyor. Okunamazsa **null
 * doner, uydurulmaz** — yanlis surum, surum bilgisi olmamasindan kotudur.
 *
 * Bir sunucunun butun dosyalarini taramiyoruz; yalnizca bilinen iki dizin.
 * Derin tarama, 30 sunuculu bir panelde dakikalar suren bir istek olurdu.
 */
class ProductController extends Controller
{
    /** Sorvia'ya ait sayilan on ekler. */
    private const ONEKLER = ['srv-', 'rxy-', 'srv_', 'rxy_'];

    /** Bakilacak dizinler ve hangi tur icerik bekledigimiz. */
    private const DIZINLER = [
        ['path' => '/plugins', 'kind' => 'minecraft-plugin'],
        ['path' => '/resources', 'kind' => 'fivem-resource'],
        ['path' => '/mods', 'kind' => 'minecraft-mod'],
    ];

    public function __construct(
        private DaemonFileRepository $depo,
        private Blueprint $blueprint,
    ) {
    }

    /** Tek sunucuda kurulu urunler. */
    public function forServer(Server $server): JsonResponse
    {
        $sonuc = $this->sunucuyuTara($server);

        return new JsonResponse([
            'ok' => true,
            'server' => [
                'id' => $server->id,
                'identifier' => $server->uuidShort,
                'name' => $server->name,
            ],
            'scanned' => $sonuc['taranan'],
            'unreachable' => $sonuc['ulasilamayan'],
            'products' => $sonuc['urunler'],
        ]);
    }

    /**
     * Butun sunuculardaki urun envanteri.
     *
     * Her sunucu icin en fazla uc dizin listesi cekiliyor. Panel buyukse
     * `?node=` ile daraltilabilir; sinir olmadan calistirmak wings'i
     * gereksiz yorar.
     */
    public function index(Request $request): JsonResponse
    {
        $sorgu = Server::query()->with(['node', 'egg']);
        if ($request->filled('node')) {
            $sorgu->where('node_id', (int) $request->query('node'));
        }

        $sinir = min(max((int) $request->query('limit', 50), 1), 200);
        $sunucular = $sorgu->orderBy('name')->limit($sinir)->get();

        $satirlar = [];
        $ulasilamayan = [];

        foreach ($sunucular as $s) {
            $sonuc = $this->sunucuyuTara($s);
            if ($sonuc['ulasilamayan'] !== []) {
                $ulasilamayan[] = [
                    'server' => $s->uuidShort,
                    'name' => $s->name,
                    'reason' => $sonuc['ulasilamayan'][0]['reason'] ?? 'bilinmiyor',
                ];
            }
            foreach ($sonuc['urunler'] as $u) {
                $satirlar[] = $u + [
                    'server' => [
                        'id' => $s->id,
                        'identifier' => $s->uuidShort,
                        'name' => $s->name,
                        'node' => $s->node->name ?? null,
                    ],
                ];
            }
        }

        // Urune gore ozet: hangi urun kac sunucuda.
        $ozet = [];
        foreach ($satirlar as $r) {
            $ad = $r['product'];
            $ozet[$ad] ??= ['product' => $ad, 'servers' => 0, 'versions' => []];
            $ozet[$ad]['servers']++;
            if ($r['version'] !== null && !in_array($r['version'], $ozet[$ad]['versions'], true)) {
                $ozet[$ad]['versions'][] = $r['version'];
            }
        }
        ksort($ozet);

        return new JsonResponse([
            'ok' => true,
            'servers_scanned' => $sunucular->count(),
            'installations' => $satirlar,
            'summary' => array_values($ozet),
            'unreachable' => $ulasilamayan,
        ]);
    }

    /** @return array{urunler: array, taranan: array, ulasilamayan: array} */
    private function sunucuyuTara(Server $server): array
    {
        $urunler = [];
        $taranan = [];
        $ulasilamayan = [];

        foreach (self::DIZINLER as $hedef) {
            try {
                $liste = $this->depo->setServer($server)->getDirectory($hedef['path']);
            } catch (DaemonConnectionException $e) {
                // Dizin yoksa ya da dugum yanit vermiyorsa: ikisi de "burada
                // bakamadik" demek. Hangisi oldugunu mesaj soyluyor.
                $ulasilamayan[] = [
                    'path' => $hedef['path'],
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            $taranan[] = $hedef['path'];

            foreach ($liste as $oge) {
                $ad = (string) ($oge['name'] ?? '');
                if ($ad === '' || !$this->sorviaMi($ad)) {
                    continue;
                }

                $urunler[] = [
                    'product' => $this->urunAdi($ad),
                    'file' => $ad,
                    'kind' => $hedef['kind'],
                    'path' => rtrim($hedef['path'], '/') . '/' . $ad,
                    'version' => $this->surumCikar($ad),
                    'size' => $oge['size'] ?? null,
                    'modified_at' => $oge['modified_at'] ?? null,
                    'is_directory' => (bool) ($oge['is_file'] ?? true) === false,
                ];
            }
        }

        return ['urunler' => $urunler, 'taranan' => $taranan, 'ulasilamayan' => $ulasilamayan];
    }

    private function sorviaMi(string $ad): bool
    {
        $kucuk = strtolower($ad);
        foreach (self::ONEKLER as $onek) {
            if (str_starts_with($kucuk, $onek)) {
                return true;
            }
        }
        return false;
    }

    /** "srv-core-1.2.0.jar" → "srv-core" */
    private function urunAdi(string $dosya): string
    {
        $ad = preg_replace('/\.(jar|zip|phar)$/i', '', $dosya) ?? $dosya;
        $ad = preg_replace('/[-_]v?\d+(\.\d+)*(-[A-Za-z0-9.]+)?$/', '', $ad) ?? $ad;
        return strtolower($ad);
    }

    /** "srv-core-1.2.0.jar" → "1.2.0"; okunamazsa null. */
    private function surumCikar(string $dosya): ?string
    {
        if (preg_match('/[-_]v?(\d+(?:\.\d+){1,3})(?:[-.][A-Za-z0-9]+)?\.(?:jar|zip|phar)$/i', $dosya, $e)) {
            return $e[1];
        }
        if (preg_match('/[-_]v?(\d+(?:\.\d+){1,3})$/', $dosya, $e)) {
            return $e[1];
        }
        return null;
    }
}
