<?php

namespace Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary as Blueprint;

/**
 * Jeton dogrulamasi.
 *
 * Panelin kendi API anahtarlari IP kisitli oldugu icin kendi kapimiz var.
 * Uc sey onemli:
 *
 *   1. Karsilastirma **sabit zamanli**. Karakter karakter kisa devre yapan
 *      bir karsilastirma, jetonu yanit suresinden tahmin edilebilir kilar.
 *   2. Jeton veritabaninda **ozet olarak** duruyor, duz metin degil. Panel
 *      veritabani dokumu tek basina erisim vermemeli.
 *   3. IP beyaz listesi **istege bagli** ve bos birakilabilir. Zorunlu
 *      kilmak, bu eklentiyi var edis sebebimizi tekrar uretirdi.
 */
class TokenMiddleware
{
    private Blueprint $blueprint;

    public function __construct(Blueprint $blueprint)
    {
        $this->blueprint = $blueprint;
    }

    public function handle(Request $request, Closure $next)
    {
        $beklenenOzet = (string) $this->blueprint->dbGet('srvpteroapi', 'token_hash');

        if ($beklenenOzet === '') {
            return $this->hata(
                503,
                'API jetonu tanimli degil.',
                'Yonetim panelinde Sorvia Ptero API bolumunden bir jeton uretin.'
            );
        }

        $verilen = $this->jetonuAl($request);
        if ($verilen === null) {
            return $this->hata(
                401,
                'Jeton verilmedi.',
                'Authorization: Bearer <jeton> ya da X-Sorvia-Token basligi gerekiyor.'
            );
        }

        // Sabit zamanli karsilastirma: hash_equals uzunluk farkinda da
        // erken cikmaz.
        if (!hash_equals($beklenenOzet, hash('sha256', $verilen))) {
            return $this->hata(401, 'Jeton gecersiz.', 'Panelden yeni bir jeton uretin.');
        }

        $izinliler = trim((string) $this->blueprint->dbGet('srvpteroapi', 'allowed_ips'));
        if ($izinliler !== '' && !$this->ipUygunMu($request->ip(), $izinliler)) {
            return $this->hata(
                403,
                'Bu IP icin izin yok.',
                sprintf('%s izinli listede degil. Panelden ekleyin ya da listeyi bosaltin.', $request->ip())
            );
        }

        return $next($request);
    }

    /** Authorization: Bearer ya da X-Sorvia-Token. */
    private function jetonuAl(Request $request): ?string
    {
        $baslik = $request->header('Authorization');
        if (is_string($baslik) && stripos($baslik, 'bearer ') === 0) {
            $deger = trim(substr($baslik, 7));
            return $deger === '' ? null : $deger;
        }

        $alternatif = $request->header('X-Sorvia-Token');
        if (is_string($alternatif) && trim($alternatif) !== '') {
            return trim($alternatif);
        }

        return null;
    }

    /**
     * Virgulle ayrilmis liste; tek IP ya da CIDR kabul ediyor.
     */
    private function ipUygunMu(?string $ip, string $liste): bool
    {
        if ($ip === null) {
            return false;
        }

        foreach (explode(',', $liste) as $parca) {
            $parca = trim($parca);
            if ($parca === '') {
                continue;
            }
            if (strpos($parca, '/') === false) {
                if ($parca === $ip) {
                    return true;
                }
                continue;
            }
            if ($this->cidrIcinde($ip, $parca)) {
                return true;
            }
        }

        return false;
    }

    private function cidrIcinde(string $ip, string $cidr): bool
    {
        [$agAdresi, $bit] = explode('/', $cidr, 2);
        $bit = (int) $bit;

        $ipBin = @inet_pton($ip);
        $agBin = @inet_pton($agAdresi);
        if ($ipBin === false || $agBin === false || strlen($ipBin) !== strlen($agBin)) {
            return false;
        }

        $tamBayt = intdiv($bit, 8);
        $kalanBit = $bit % 8;

        if ($tamBayt > 0 && strncmp($ipBin, $agBin, $tamBayt) !== 0) {
            return false;
        }
        if ($kalanBit === 0) {
            return true;
        }

        $maske = chr(0xff << (8 - $kalanBit) & 0xff);
        return (($ipBin[$tamBayt] & $maske) === ($agBin[$tamBayt] & $maske));
    }

    /**
     * Hata yaniti — "bir hata olustu" yok.
     *
     * Her yanit ne oldugunu ve ne yapilacagini soyluyor; cagiran taraf
     * (Command Center) bunu oldugu gibi kullaniciya gosteriyor.
     */
    private function hata(int $kod, string $mesaj, string $nasil): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => [
                'message' => $mesaj,
                'how' => $nasil,
            ],
        ], $kod);
    }
}
