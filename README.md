# srv-pteroapi

Pterodactyl paneli için Blueprint eklentisi. Sorvia Command Center'ın panele
tek bir jetonla, tek bir çağrıda erişmesini sağlar.

## Neden

Stok Pterodactyl API'si üç yerde yetersiz kalıyordu:

1. **API anahtarları IP kısıtlı.** Kontrol düzlemi başka bir sunucudan
   çağırıyor; her IP değişikliğinde anahtarı düzenlemek gerekiyordu ve
   pratikte `This IP address does not have permission` hatasıyla kalındı.
2. **Tek çağrıda bütünü vermiyor.** Sunucu listesi node, egg, sahip ve
   allocation bilgisini ayrı uçlarda tutuyor; 30 sunuculu bir panelde
   kontrol düzleminin her açılışı yüzlerce istek demekti.
3. **Dosya ve konsol yalnızca client API'de**, sunucu sahibinin jetonuyla.
   Panel yöneticisi olarak tek jetonla bütün sunuculara erişmek yok.

Bu eklenti üçünü de çözüyor: kendi kimlik doğrulaması, birleşik yanıtlar,
yönetici düzeyinde dosya ve konsol erişimi.

## Kurulum

`blueprint -install` bir **klasör değil, `.blueprint` dosyası** arar ve o
dosya panelin kök dizininde olmalıdır. Depoyu klonlamak tek başına yetmez.

```bash
cd /tmp
git clone https://github.com/sorvia-net/srv-pteroapi.git
cp /tmp/srv-pteroapi/srv-pteroapi.blueprint /var/www/pterodactyl/
cd /var/www/pterodactyl
blueprint -install srv-pteroapi
```

Kaynaktan yeniden paketlemek isterseniz (kodu değiştirdiyseniz):

```bash
cd /tmp/srv-pteroapi && ./paketle.sh
cp srv-pteroapi.blueprint /var/www/pterodactyl/
```

Kurulumdan sonra **Yönetim → Extensions → Sorvia Ptero API** bölümünden
**Yeni jeton üret** deyin. Jeton bir kez gösterilir.

Jetonu Command Center'da Pterodactyl entegrasyonunun `extension_token`
alanına girin.

### Kaldırma

```bash
cd /var/www/pterodactyl && blueprint -remove srvpteroapi
```

Kaldırma komutu **identifier** ister (`srvpteroapi`), kurulum ise dosya adını
(`srv-pteroapi`). Blueprint'in bu ikiliği kafa karıştırıcı ama böyle.

## Uçlar

Taban adres: `https://<panel>/extensions/srvpteroapi/v1`
Kimlik: `Authorization: Bearer <jeton>` ya da `X-Sorvia-Token: <jeton>`

Taban adresteki `/extensions/srvpteroapi` **Blueprint'in kendi eklediği**
önektir, bizim seçimimiz değil — ayrıntısı aşağıdaki notlarda.

| Uç | Ne döner |
|---|---|
| `GET /ping` | Eklenti sürümü, panel sürümü |
| `GET /overview` | Düğüm/sunucu/kullanıcı sayıları, düğüm kapasiteleri |
| `GET /nodes` | Düğümler, ayrılan ve toplam kaynak |
| `GET /nodes/{id}` | Düğüm detayı, **canlı wings durumu**, allocation sayıları |
| `GET /servers` | Bütün sunucular; node, egg, sahip, birincil port dahil |
| `GET /servers/{id}` | Limitler, allocation'lar, tanımlı değişkenler |
| `GET /servers/{id}/usage` | Canlı CPU/RAM/disk |
| `POST /servers/{id}/power` | `{"signal":"start\|stop\|restart\|kill"}` |
| `POST /servers/{id}/command` | `{"command":"say merhaba"}` |
| `GET /servers/{id}/files?path=/` | Dizin listesi |
| `GET /servers/{id}/files/contents?path=…` | Dosya içeriği (en fazla 2 MB) |
| `PUT /servers/{id}/files/contents` | `{"path":"…","content":"…"}` |
| `POST /servers/{id}/files/rename` | `{"root":"/","from":"a","to":"b"}` |
| `DELETE /servers/{id}/files` | `{"root":"/","files":["a","b"]}` |
| `GET /products` | Bütün sunuculardaki Sorvia ürünleri + özet |
| `GET /servers/{id}/products` | Tek sunucudaki ürünler |

Filtreler: `/servers?node=2&search=lobby`, `/products?node=2&limit=50`

## Ürün tespiti nasıl çalışıyor

"Hangi sunucuda hangi ürünüm var" sorusunun cevabı bugün hiçbir yerde yok:
satış kaydı sorvia.net'te, kurulum sunucuda, ikisi birbirini bilmiyor.

`/products` ucu üç dizine bakıyor — `plugins/`, `resources/`, `mods/` — ve
`srv-` / `rxy-` ile başlayanları listeliyor. Sürüm dosya adındaki `-1.2.3`
kalıbından okunuyor; **okunamazsa `null` döner, uydurulmaz.** Yanlış sürüm,
sürüm bilgisi olmamasından kötüdür.

Sunucunun bütün dosyaları taranmıyor. Derin tarama, 30 sunuculu bir panelde
dakikalar süren bir istek olurdu.

## Güvenlik

- Jeton veritabanında **yalnızca SHA-256 özeti olarak** duruyor. Panel
  veritabanının dökümü tek başına API erişimi vermez.
- Karşılaştırma **sabit zamanlı** (`hash_equals`) — yanıt süresinden jeton
  tahmin edilemesin.
- IP beyaz listesi **isteğe bağlı**. Zorunlu kılmak, bu eklentiyi var ediş
  sebebini tekrar üretirdi. Sabit IP'niz varsa doldurun.
- Bütün dosya ve güç işlemleri Pterodactyl'in kendi servis katmanından
  geçiyor; doğrudan SQL ya da dosya sistemi dokunuşu yok. Wings'in sunucu
  kökü dışına çıkmama kontrolü devrede kalıyor.
- Sunucu değişkenlerinin **değerleri dönmüyor** — RCON parolası ya da lisans
  anahtarı taşıyabilirler. Yalnızca hangi değişkenin tanımlı olduğu bildiriliyor.

## Hata biçimi

Her hata ne olduğunu ve ne yapılacağını söyler:

```json
{
  "ok": false,
  "error": {
    "message": "Komut gonderilemedi.",
    "how": "Sunucu calisiyor mu? Kapali bir sunucunun konsoluna komut gitmez.",
    "detail": "…"
  }
}
```

## Blueprint notları

Bu bölüm 1.0.0'ın kurulamamasının sebeplerini kayıt altına alıyor. Dördü de
dokümantasyondan değil, framework kaynağından çıktı.

**1. `info.target` Blueprint sürümüdür, Pterodactyl sürümü değil.**
Kurulum betiğindeki karşılaştırma şu:

```bash
if [[ $target != "$VERSION" ]]; then PRINT WARNING "... is built for version $target, but your version is $VERSION."; fi
```

`$VERSION` Blueprint'in kendi sürümü (`beta-2026-06`). Oraya `1.11.11` yazmak
panelin Pterodactyl sürümünü değil, Blueprint sürümünü yanlış beyan etmek
oluyordu.

**2. `conf.yml`'de yazılan her yol pakette gerçekten var olmalı.**
Kurulum betiği hepsini tek bir `if` bloğunda kontrol edip
`Extension configuration points towards one or more files that do not exist`
ile duruyor — hangisinin eksik olduğunu **söylemiyor**. Bizde dördü birden
eksikti: `views/`, `database/migrations/` ve `data/` boş oldukları için git ve
zip içinde hayatta kalmamışlardı (`data/` ayrıca `.gitignore`'daydı),
`requests.controllers` ise taşınmış bir klasörü gösteriyordu. Kullanılmayan
anahtarı silmek, boş klasörü `.gitkeep` ile ayakta tutmaya tercih edilir.

**3. Web rotaları iki önek alır.** `RouteServiceProvider` `/extensions` ekler,
`routes/blueprint/web.php` ise dosya adından eklenti tanımlayıcısını ekler.
Router dosyasında yazdığınız önek bunların **üstüne** biner:

```
routers/web.php içinde Route::prefix('v1')
        ↓
/extensions/srvpteroapi/v1/...
```

**4. Web rotaları CSRF korumasındadır.** `blueprint` middleware grubu
`VerifyCsrfToken` içeriyor. Tarayıcıdan değil sunucudan çağrılan bir API'de
CSRF jetonu diye bir şey olmadığından bütün `POST`/`PUT`/`DELETE` istekleri
419 dönerdi. Çözüm rota grubunda
`->withoutMiddleware([VerifyCsrfToken::class])`. Kimlik doğrulamayı zaten
kendi `TokenMiddleware`'imiz yapıyor.

**Bonus — admin controller'ın adı serbest değil.** Blueprint
`admin.controller` dosyasını namespace'ine dokunmadan şuraya kopyalıyor:

```
app/Http/Controllers/Admin/Extensions/{identifier}/{identifier}ExtensionController.php
```

Dosyadaki namespace ve sınıf adı bu yolun tam karşılığı olmak zorunda. Rota
adları da framework tarafından üretiliyor: `index` (GET), `post` (POST),
`update` (PATCH), `put` (PUT), `delete` (DELETE). Yönetim sayfasındaki formlar
POST gönderdiği için iş mantığı `post()` içinde.

**Neden `web` router, `application`/`client` değil?** Diğer ikisi sırasıyla
`/api/application/extensions/{id}/` ve `/api/client/extensions/{id}/` öneklerini
alıyor ama panelin kendi API anahtarını şart koşuyorlar — yani bu eklentinin
var oluş sebebi olan IP kısıtını geri getirirlerdi.

## Sürüm

| | |
|---|---|
| Eklenti | 1.0.1 |
| Hedef Blueprint | `beta-2026-06` |
| Panel | Pterodactyl 1.11.x |

**Test durumu:** 1.0.0 canlı panele kurulamadı; 1.0.1 yukarıdaki dört hatayı
düzeltiyor ama **uçlar henüz canlı panele karşı çalıştırılmadı.** İlk kurulumu
test olarak görün. `Repositories\Wings\*` sınıf adları 1.11.11 dışındaki
sürümlerde değişmiş olabilir.
