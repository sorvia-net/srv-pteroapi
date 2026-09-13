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
    /**
     * Sorvia'ya ait sayilan on ekler.
     *
     * Ayrac **istege bagli**: canli panelde gercek dosya adlari
     * `SrvHubPvP-1.0.0.jar` ve `RxyJoinCommands-1.0.jar` cikti. Depolar
     * `srv-hubpvp` diye adlandirilmis olsa da derlenen artifact projenin
     * gorunen adini aliyor. Yalnizca `srv-` arasak hicbir sey bulamazdik —
     * nitekim ilk taramada 14 sunucuda sifir sonuc verdi.
     *
     * On ek **basta** aranıyor, icerde degil: `DiscordSRV` icinde "srv"
     * geciyor ama bizim urunumuz degil.
     */
    private const ONEKLER = ['srv', 'rxy'];

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
            'absent' => $sonuc['yok'],
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

    /**
     * Tek sunucuyu tarar.
     *
     * Once **kok dizin** listeleniyor, sonra yalnizca gercekten var olan
     * hedef dizinlere bakiliyor.
     *
     * Sebep: wings, olmayan bir dizin icin de kodu 500 olan bir hata
     * donuyor ve Pterodactyl bunu erisim hatasiyla ayni istisnaya sariyor.
     * Dogrudan /plugins listelemek, plugins dizini olmayan her sunucuyu
     * "erisilemiyor" diye isaretliyordu — 14 sunucunun 14'u birden. Yanlis
     * teshis, teshis olmamasindan kotudur.
     *
     * Yan fayda: sunucu basina uc istek yerine bir istek artı yalnizca var
     * olan dizinler kadar istek.
     *
     * @return array{urunler: array, taranan: array, yok: array, ulasilamayan: array}
     */
    private function sunucuyuTara(Server $server): array
    {
        try {
            $kok = $this->depo->setServer($server)->getDirectory('/');
        } catch (DaemonConnectionException $e) {
            // Kok dizin okunamiyorsa sunucu gercekten erisilemez durumda.
            return [
                'urunler' => [],
                'taranan' => [],
                'yok' => [],
                'ulasilamayan' => [['path' => '/', 'reason' => $e->getMessage()]],
            ];
        }

        $kokAdlari = [];
        foreach ($kok as $oge) {
            $ad = (string) ($oge['name'] ?? '');
            if ($ad !== '') {
                $kokAdlari[$ad] = true;
            }
        }

        $urunler = [];
        $taranan = [];
        $yok = [];
        $ulasilamayan = [];

        foreach (self::DIZINLER as $hedef) {
            $ad = ltrim($hedef['path'], '/');

            // Dizin kokte gorunmuyorsa bu bir hata degil: o sunucuda o tur
            // icerik yok. Istek bile atmiyoruz.
            if (!isset($kokAdlari[$ad])) {
                $yok[] = $hedef['path'];
                continue;
            }

            try {
                $liste = $this->depo->setServer($server)->getDirectory($hedef['path']);
            } catch (DaemonConnectionException $e) {
                // Kok okunabildigi hâlde burasi okunamiyorsa gercekten bir
                // sorun var (izin, bozuk baglama, yaris durumu).
                $ulasilamayan[] = [
                    'path' => $hedef['path'],
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            $taranan[] = $hedef['path'];

            foreach ($liste as $oge) {
                $dosyaAdi = (string) ($oge['name'] ?? '');
                if ($dosyaAdi === '' || !$this->sorviaMi($dosyaAdi)) {
                    continue;
                }

                $dizinMi = ((bool) ($oge['is_file'] ?? true)) === false;
                if (!$this->artifactMi($hedef, $dosyaAdi, $dizinMi)) {
                    continue;
                }

                $urunler[] = [
                    'product' => $this->urunAdi($dosyaAdi),
                    'file' => $dosyaAdi,
                    'kind' => $hedef['kind'],
                    'path' => rtrim($hedef['path'], '/') . '/' . $dosyaAdi,
                    'version' => $this->surumCikar($dosyaAdi),
                    'size' => $oge['size'] ?? null,
                    'modified_at' => $oge['modified_at'] ?? null,
                    'is_directory' => $dizinMi,
                ];
            }
        }

        return [
            'urunler' => $urunler,
            'taranan' => $taranan,
            'yok' => $yok,
            'ulasilamayan' => $ulasilamayan,
        ];
    }

    private function sorviaMi(string $ad): bool
    {
        $kucuk = strtolower($ad);
        foreach (self::ONEKLER as $onek) {
            if (!str_starts_with($kucuk, $onek)) {
                continue;
            }

            // On ekten sonra ya ayrac ya da yeni bir kelime gelmeli.
            // Boylece "srvany" gibi rastgele bir ad esiklenmiyor ama
            // "SrvHubPvP" ve "srv-core" ikisi de yakalaniyor.
            $kalan = substr($ad, strlen($onek));
            if ($kalan === '') {
                continue;
            }
            $ilk = $kalan[0];
            if ($ilk === '-' || $ilk === '_' || ctype_upper($ilk)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bu dosya bu dizinde bir urun sayilir mi?
     *
     * Minecraft'ta artifact **jar**; yanindaki ayni adli klasor eklentinin
     * ayar dizini ve ayri bir kurulum degil — ikisini birden saymak her
     * urunu iki kez gostermek olurdu. FiveM'de ise kaynagin kendisi bir
     * dizin, orada tersi gecerli.
     */
    private function artifactMi(array $hedef, string $ad, bool $dizinMi): bool
    {
        if ($hedef['kind'] === 'fivem-resource') {
            return $dizinMi;
        }
        return !$dizinMi && preg_match('/\.(jar|phar)$/i', $ad) === 1;
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
