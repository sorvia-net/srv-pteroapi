<?php

/*
 * Blueprint bu dosyayi **oldugu gibi** kopyaliyor:
 *   admin/Controller.php -> app/Http/Controllers/Admin/Extensions/srvpteroapi/
 *                           srvpteroapiExtensionController.php
 *
 * Namespace ve sinif adini Blueprint duzeltmiyor. Bu yuzden ikisi de
 * hedefteki yolun tam karsiligi olmak zorunda; "Controller" adinda bir
 * sinif birakirsak yonetim sayfasi autoload hatasiyla 500 doner.
 *
 * Rota adlari routes/blueprint.php tarafindan uretiliyor:
 *   GET    /admin/extensions/srvpteroapi        -> index()
 *   POST   /admin/extensions/srvpteroapi        -> post()
 *   PATCH  /admin/extensions/srvpteroapi        -> update()
 * Formlar POST gonderdigi icin islem mantigi post() icinde.
 */

namespace Pterodactyl\Http\Controllers\Admin\Extensions\srvpteroapi;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Helpers\SoftwareVersionService;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;

class srvpteroapiExtensionController extends Controller
{
    public function __construct(
        private BlueprintExtensionLibrary $blueprint,
        private SoftwareVersionService $version,
        private ViewFactory $view,
    ) {
    }

    public function index(): View
    {
        return $this->view->make('admin.extensions.srvpteroapi.index', [
            'blueprint' => $this->blueprint,
            'version' => $this->version,
            'root' => '/admin/extensions/srvpteroapi',
        ]);
    }

    /**
     * Jeton uretimi, iptal ve IP listesi.
     *
     * Jeton **yalnizca ozet olarak** saklaniyor. Panel veritabaninin dokumu
     * tek basina API erisimi vermemeli; ozetten jeton geri uretilemez.
     * Uretilen jeton bir kez gosteriliyor; kaybedilirse yenisi uretilir.
     */
    public function post(Request $request): RedirectResponse
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

        // IP listesi bos birakilabilir. Zorunlu kilmak, bu eklentiyi var
        // edis sebebimizi (IP kisitina takilmak) tekrar uretirdi.
        $this->blueprint->dbSet('srvpteroapi', 'allowed_ips', trim((string) $request->input('allowed_ips', '')));

        return redirect()->back()->with('success', 'Ayarlar kaydedildi.');
    }

    /** PATCH ile gelen istekler de ayni mantigi kullansin. */
    public function update(Request $request): RedirectResponse
    {
        return $this->post($request);
    }
}
