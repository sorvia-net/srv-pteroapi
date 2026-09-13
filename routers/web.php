<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\FileController;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\NodeController;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\OverviewController;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\ProductController;
use Pterodactyl\BlueprintFramework\Extensions\srvpteroapi\Controllers\ServerController;
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
*/

Route::prefix('api/sorvia/v1')
    ->middleware(TokenMiddleware::class)
    ->group(function () {
        // ── durum ──
        Route::get('/ping', [OverviewController::class, 'ping']);
        Route::get('/overview', [OverviewController::class, 'overview']);

        // ── dugumler ──
        Route::get('/nodes', [NodeController::class, 'index']);
        Route::get('/nodes/{node}', [NodeController::class, 'show']);

        // ── sunucular ──
        Route::get('/servers', [ServerController::class, 'index']);
        Route::get('/servers/{server}', [ServerController::class, 'show']);
        Route::get('/servers/{server}/usage', [ServerController::class, 'usage']);
        Route::post('/servers/{server}/power', [ServerController::class, 'power']);
        Route::post('/servers/{server}/command', [ServerController::class, 'command']);

        // ── dosyalar ──
        Route::get('/servers/{server}/files', [FileController::class, 'index']);
        Route::get('/servers/{server}/files/contents', [FileController::class, 'read']);
        Route::put('/servers/{server}/files/contents', [FileController::class, 'write']);
        Route::post('/servers/{server}/files/rename', [FileController::class, 'rename']);
        Route::delete('/servers/{server}/files', [FileController::class, 'delete']);

        // ── Sorvia urunleri ──
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/servers/{server}/products', [ProductController::class, 'forServer']);
    });
