<?php

namespace Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Dosya islemleri.
 *
 * Stok panelde bu uclar yalnizca client API'de ve sunucu sahibinin jetonunu
 * istiyor; yonetici olarak tek jetonla butun sunuculara erisim yok. Bu
 * eklenti o bosluğu dolduruyor.
 *
 * Butun islemler Pterodactyl'in `DaemonFileRepository` katmanindan geciyor —
 * dogrudan dosya sistemine dokunmuyoruz. Bu, wings'in kendi guvenlik
 * kontrollerinin (sunucu koku disina cikamama) devrede kalmasi demek.
 */
class FileController extends Controller
{
    /** Okunabilir kabul edilen en buyuk dosya. Uzerini metin olarak donmuyoruz. */
    private const OKUMA_SINIRI = 2 * 1024 * 1024;

    public function __construct(private DaemonFileRepository $depo)
    {
    }

    public function index(Request $request, Server $server): JsonResponse
    {
        $yol = $this->yolAl($request, '/');

        try {
            $liste = $this->depo->setServer($server)->getDirectory($yol);
        } catch (DaemonConnectionException $e) {
            return $this->daemonHatasi($server, $e);
        }

        return new JsonResponse([
            'ok' => true,
            'path' => $yol,
            'entries' => $liste,
        ]);
    }

    public function read(Request $request, Server $server): JsonResponse
    {
        $yol = $this->yolAl($request);
        if ($yol === null) {
            return $this->eksikYol();
        }

        try {
            $icerik = $this->depo->setServer($server)->getContent($yol, self::OKUMA_SINIRI);
        } catch (DaemonConnectionException $e) {
            return $this->daemonHatasi($server, $e);
        }

        return new JsonResponse([
            'ok' => true,
            'path' => $yol,
            'size' => strlen($icerik),
            'content' => $icerik,
        ]);
    }

    public function write(Request $request, Server $server): JsonResponse
    {
        $yol = $this->yolAl($request);
        if ($yol === null) {
            return $this->eksikYol();
        }

        // Icerik null olabilir ama bos dizgi gecerli: bir dosyayi bosaltmak
        // mesru bir islem.
        $icerik = $request->input('content');
        if (!is_string($icerik)) {
            return new JsonResponse([
                'ok' => false,
                'error' => [
                    'message' => 'Icerik verilmedi.',
                    'how' => 'content alani dizgi olmali. Dosyayi bosaltmak icin bos dizgi gonderin.',
                ],
            ], 422);
        }

        try {
            $this->depo->setServer($server)->putContent($yol, $icerik);
        } catch (DaemonConnectionException $e) {
            return $this->daemonHatasi($server, $e);
        }

        return new JsonResponse(['ok' => true, 'path' => $yol, 'size' => strlen($icerik)]);
    }

    public function rename(Request $request, Server $server): JsonResponse
    {
        $kok = (string) $request->input('root', '/');
        $eski = (string) $request->input('from', '');
        $yeni = (string) $request->input('to', '');

        if ($eski === '' || $yeni === '') {
            return new JsonResponse([
                'ok' => false,
                'error' => [
                    'message' => 'Kaynak ya da hedef ad bos.',
                    'how' => 'from ve to alanlarini doldurun; root varsayilan olarak /.',
                ],
            ], 422);
        }

        try {
            $this->depo->setServer($server)->renameFiles($kok, [['from' => $eski, 'to' => $yeni]]);
        } catch (DaemonConnectionException $e) {
            return $this->daemonHatasi($server, $e);
        }

        return new JsonResponse(['ok' => true, 'root' => $kok, 'from' => $eski, 'to' => $yeni]);
    }

    public function delete(Request $request, Server $server): JsonResponse
    {
        $kok = (string) $request->input('root', '/');
        $dosyalar = $request->input('files');

        if (!is_array($dosyalar) || $dosyalar === []) {
            return new JsonResponse([
                'ok' => false,
                'error' => [
                    'message' => 'Silinecek dosya verilmedi.',
                    'how' => 'files alani en az bir ad iceren dizi olmali.',
                ],
            ], 422);
        }

        try {
            $this->depo->setServer($server)->deleteFiles($kok, array_values($dosyalar));
        } catch (DaemonConnectionException $e) {
            return $this->daemonHatasi($server, $e);
        }

        return new JsonResponse([
            'ok' => true,
            'root' => $kok,
            'deleted' => count($dosyalar),
        ]);
    }

    private function yolAl(Request $request, ?string $varsayilan = null): ?string
    {
        $yol = $request->query('path') ?? $request->input('path') ?? $varsayilan;
        if (!is_string($yol) || trim($yol) === '') {
            return $varsayilan;
        }
        return $yol;
    }

    private function eksikYol(): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => ['message' => 'Yol verilmedi.', 'how' => 'path parametresini ekleyin.'],
        ], 422);
    }

    /**
     * Wings hatasi.
     *
     * Dikkat: wings **olmayan bir dosya ya da dizin icin de** ayni hatayi
     * donuyor ve Pterodactyl ikisini ayni istisnaya sariyor. Bu yuzden
     * "dugum cevap vermiyor" diye kesin konusmuyoruz — olcumun soyleyemedigi
     * seyi soylemek, hatanin kendisinden cok zaman kaybettirir.
     */
    private function daemonHatasi(Server $s, DaemonConnectionException $e): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => [
                'message' => 'Dosya islemi tamamlanamadi.',
                'how' => sprintf(
                    'Iki ihtimal var: yol yok, ya da %s dugumundeki wings yanit vermiyor. '
                    . 'Once ust dizini listeleyin — o calisiyorsa sorun yolda, dugumde degil.',
                    $s->node->name ?? 'bilinmeyen'
                ),
                'detail' => $e->getMessage(),
            ],
        ], 502);
    }
}
