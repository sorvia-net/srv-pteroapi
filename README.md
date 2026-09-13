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

```bash
# Panel sunucusunda, blueprint kurulu olmalı
cd /var/www/pterodactyl
blueprint -install srv-pteroapi
```

Kurulumdan sonra **Yönetim → Extensions → Sorvia Ptero API** bölümünden
**Yeni jeton üret** deyin. Jeton bir kez gösterilir.

Jetonu Command Center'da Pterodactyl entegrasyonunun `extension_token`
alanına girin.

## Uçlar

Taban adres: `https://<panel>/api/sorvia/v1`
Kimlik: `Authorization: Bearer <jeton>` ya da `X-Sorvia-Token: <jeton>`

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

## Sürüm

| | |
|---|---|
| Eklenti | 1.0.0 |
| Hedef panel | Pterodactyl 1.11.x |
| Blueprint | ≥ 1.6 |

**Test durumu:** canlı panele karşı henüz çalıştırılmadı. Yazıldığı hedef
sürüm 1.11.11; farklı bir sürümde `Repositories\Wings\*` sınıf adları
değişmiş olabilir.
