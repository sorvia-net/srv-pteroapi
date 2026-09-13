<?php

namespace Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as Blueprint;

/**
 * Yonetim paneli: jeton uretimi ve IP listesi.
 *
 * Jeton **yalnizca ozet olarak** saklaniyor. Panel veritabaninin dokumu
 * tek basina API erisimi vermemeli; ozetten jeton geri uretilemez.
 *
 * Uretilen jeton bir kez gosteriliyor. Kaybedilirse yenisi uretilir —
 * eskisini "hatirlatmak" mumkun degil ve olmamali.
 */
class Controller extends \Pterodactyl\Http\Controllers\Admin\Extensions\BlueprintExtensionController
{
    public function __construct(private Blueprint $blueprint)
    {
        parent::__construct();
    }

    public function update(Request $request): RedirectResponse
    {
        $eylem = (string) $request->input('action', '');

        if ($eylem === 'generate') {
            // 32 bayt = 64 hex karakter. Tahmin edilemez olmasi icin
            // rastgeleligin kriptografik olmasi sart.
            $jeton = bin2hex(random_bytes(32));

            $this->blueprint->dbSet('srvpteroapi', 'token_hash', hash('sha256', $jeton));
            $this->blueprint->dbSet('srvpteroapi', 'token_prefix', substr($jeton, 0, 8));
            $this->blueprint->dbSet('srvpteroapi', 'token_created', now()->toIso8601String());

            // Bir kez gosterilecek; oturumdan sonra silinir.
            $request->session()->flash('srvpteroapi_token', $jeton);

            return redirect()->back()->with('success', 'Yeni jeton uretildi. Asagida bir kez gosteriliyor.');
        }

        if ($eylem === 'revoke') {
            $this->blueprint->dbSet('srvpteroapi', 'token_hash', '');
            $this->blueprint->dbSet('srvpteroapi', 'token_prefix', '');
            $this->blueprint->dbSet('srvpteroapi', 'token_created', '');

            return redirect()->back()->with('success', 'Jeton iptal edildi. API kapali.');
        }

        // IP listesi: bos birakilabilir. Zorunlu kilmak, bu eklentiyi var
        // edis sebebimizi (IP kisitina takilmak) tekrar uretirdi.
        $ipler = trim((string) $request->input('allowed_ips', ''));
        $this->blueprint->dbSet('srvpteroapi', 'allowed_ips', $ipler);

        return redirect()->back()->with('success', 'Ayarlar kaydedildi.');
    }
}
