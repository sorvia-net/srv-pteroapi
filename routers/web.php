<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\FileController;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\NodeController;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\OverviewController;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\ProductController;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\ServerController;
use Pterodactyl\Http\Middleware\VerifyCsrfToken;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Middleware\TokenMiddleware;

/*
|--------------------------------------------------------------------------
| Sorvia Ptero API
|--------------------------------------------------------------------------
|
| Neden Pterodactyl'in kendi application API'si degil:
|
|   1. Panelin API anahtarlari IP kisitli. Kontrol duzlemi baska bir
|      sunucudan cagiriyor ve her IP degisikliginde anahtar duzenlemek
|      gerekiyordu — pratikte "bu IP'nin izni yok" hatasiyla kalindi.
|   2. Stok API bir cagrida node + egg + allocation + sahip bilgisini
|      birlikte vermiyor; 30 sunuculu bir panelde bu yuzlerce istek demek.
|   3. Dosya ve konsol uclari yalnizca client API'de ve sunucu sahibi
|      jetonu istiyor; panel yoneticisi olarak tek jetonla erismek yok.
|
| Bu yuzden kendi kimlik dogrulamamiz var: sabit bir jeton, sabit zamanli
| karsilastirma, istege bagli IP beyaz listesi. Jeton yonetim panelinden
| uretiliyor ve yalnizca ozetinin yani sira bir kez gosteriliyor.
|
| Butun uclar salt okuma degil — guc ve dosya yazma da var — ama hepsi
| Pterodactyl'in kendi servis katmanindan geciyor; dogrudan SQL ya da
| dosya sistemi dokunusu yok.
|
| Yol onegi:
|   Blueprint web rotalarina iki onek ekliyor — RouteServiceProvider
|   "/extensions", routes/blueprint/web.php ise eklenti tanimlayicisi.
|   Dolayisiyla asagidaki "v1" oneki su adrese denk geliyor:
|
|       /extensions/srvpteroapi/v1/...
|
| CSRF:
|   Web rotalari "blueprint" middleware grubunda ve o grup
|   VerifyCsrfToken iceriyor. Tarayicidan degil sunucudan cagrilan bir
|   API icin CSRF jetonu diye bir sey yok; disarida birakilmazsa butun
|   POST/PUT/DELETE istekleri 419 doner. Kimlik dogrulamasini zaten
|   TokenMiddleware yapiyor.
|
| Baglama anahtari:
|   Pterodactyl'in taban modeli getRouteKeyName() ile "uuid" donduruyor.
|   Yani {server} yazmak sayisal id ile 404 demek. Listeleme uclari id
|   veriyor; kontrol duzlemi de id ile cagiriyor. Bu yuzden anahtar
|   rotada acikca belirtiliyor: {server:id}.
*/

Route::prefix('v1')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware(TokenMiddleware::class)
    ->group(function () {
        // ── durum ──
        Route::get('/ping', [OverviewController::class, 'ping']);
        Route::get('/overview', [OverviewController::class, 'overview']);

        // ── dugumler ──
        Route::get('/nodes', [NodeController::class, 'index']);
        Route::get('/nodes/{node:id}', [NodeController::class, 'show']);

        // ── sunucular ──
        Route::get('/servers', [ServerController::class, 'index']);
        Route::get('/servers/{server:id}', [ServerController::class, 'show']);
        Route::get('/servers/{server:id}/usage', [ServerController::class, 'usage']);
        Route::post('/servers/{server:id}/power', [ServerController::class, 'power']);
        Route::post('/servers/{server:id}/command', [ServerController::class, 'command']);

        // ── dosyalar ──
        Route::get('/servers/{server:id}/files', [FileController::class, 'index']);
        Route::get('/servers/{server:id}/files/contents', [FileController::class, 'read']);
        Route::put('/servers/{server:id}/files/contents', [FileController::class, 'write']);
        Route::post('/servers/{server:id}/files/rename', [FileController::class, 'rename']);
        Route::delete('/servers/{server:id}/files', [FileController::class, 'delete']);

        // ── Sorvia urunleri ──
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/servers/{server:id}/products', [ProductController::class, 'forServer']);
    });
