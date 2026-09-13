{{-- Sorvia Ptero API — yonetim ekrani --}}

<div class="row">
  <div class="col-xs-12">
    <div class="box">
      <div class="box-header with-border">
        <h3 class="box-title">Sorvia Command Center baglantisi</h3>
      </div>

      <div class="box-body">
        <p>
          Bu eklenti, kontrol duzleminin panele tek bir jetonla erismesini
          saglar. Panelin kendi API anahtarlari IP kisitli oldugu icin ayri
          bir kapi kullaniliyor.
        </p>

        @if (session('srvpteroapi_token'))
          <div class="callout callout-warning">
            <h4>Jeton bir kez gosteriliyor</h4>
            <p>Kopyalayip Command Center'a girin. Bu sayfadan ayrildiginizda bir daha gosterilmez.</p>
            <pre style="user-select:all;font-size:13px;">{{ session('srvpteroapi_token') }}</pre>
          </div>
        @endif

        <table class="table table-bordered" style="max-width:640px">
          <tbody>
            <tr>
              <td style="width:180px"><strong>Durum</strong></td>
              <td>
                @if ($blueprint->dbGet('srvpteroapi', 'token_hash'))
                  <span class="label label-success">acik</span>
                @else
                  <span class="label label-default">jeton yok — API kapali</span>
                @endif
              </td>
            </tr>
            <tr>
              <td><strong>Jeton onu</strong></td>
              <td><code>{{ $blueprint->dbGet('srvpteroapi', 'token_prefix') ?: '—' }}…</code></td>
            </tr>
            <tr>
              <td><strong>Uretildi</strong></td>
              <td>{{ $blueprint->dbGet('srvpteroapi', 'token_created') ?: '—' }}</td>
            </tr>
            <tr>
              <td><strong>Taban adres</strong></td>
              <td><code>{{ url('/api/sorvia/v1') }}</code></td>
            </tr>
          </tbody>
        </table>

        <form action="{{ route('admin.extensions.srvpteroapi.index') }}" method="POST" style="display:inline-block">
          @csrf
          <input type="hidden" name="action" value="generate">
          <button type="submit" class="btn btn-primary">Yeni jeton uret</button>
        </form>

        @if ($blueprint->dbGet('srvpteroapi', 'token_hash'))
          <form action="{{ route('admin.extensions.srvpteroapi.index') }}" method="POST" style="display:inline-block;margin-left:8px">
            @csrf
            <input type="hidden" name="action" value="revoke">
            <button type="submit" class="btn btn-danger">Jetonu iptal et</button>
          </form>
        @endif
      </div>
    </div>
  </div>

  <div class="col-xs-12">
    <div class="box">
      <div class="box-header with-border">
        <h3 class="box-title">IP kisiti</h3>
      </div>

      <form action="{{ route('admin.extensions.srvpteroapi.index') }}" method="POST">
        @csrf
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">Izinli IP adresleri</label>
            <input
              type="text"
              name="allowed_ips"
              class="form-control"
              value="{{ $blueprint->dbGet('srvpteroapi', 'allowed_ips') }}"
              placeholder="45.87.120.147, 10.0.0.0/8"
            >
            <p class="text-muted small">
              Virgulle ayirin; tek IP ya da CIDR yazabilirsiniz.
              <strong>Bos birakirsaniz her IP kabul edilir</strong> — jeton tek
              basina yeterli sayilir. Sabit IP'niz varsa doldurmak guvenligi
              artirir; degisken IP'de bos birakmak dogru olandir.
            </p>
          </div>
        </div>

        <div class="box-footer">
          <button type="submit" class="btn btn-primary pull-right">Kaydet</button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-xs-12">
    <div class="box box-solid">
      <div class="box-header with-border">
        <h3 class="box-title">Uclar</h3>
      </div>
      <div class="box-body">
        <pre style="font-size:12px;line-height:1.7">GET    /api/sorvia/v1/ping
GET    /api/sorvia/v1/overview                      panel ozeti, dugum kapasiteleri
GET    /api/sorvia/v1/nodes                         dugumler
GET    /api/sorvia/v1/nodes/{id}                    dugum detayi + canli durum
GET    /api/sorvia/v1/servers                       butun sunucular (node, egg, sahip, port)
GET    /api/sorvia/v1/servers/{id}                  limitler, allocation'lar, degiskenler
GET    /api/sorvia/v1/servers/{id}/usage            canli CPU/RAM/disk
POST   /api/sorvia/v1/servers/{id}/power            {"signal":"start|stop|restart|kill"}
POST   /api/sorvia/v1/servers/{id}/command          {"command":"say merhaba"}
GET    /api/sorvia/v1/servers/{id}/files?path=/     dizin listesi
GET    /api/sorvia/v1/servers/{id}/files/contents   dosya oku
PUT    /api/sorvia/v1/servers/{id}/files/contents   dosya yaz
POST   /api/sorvia/v1/servers/{id}/files/rename     yeniden adlandir
DELETE /api/sorvia/v1/servers/{id}/files            sil
GET    /api/sorvia/v1/products                      butun sunuculardaki Sorvia urunleri
GET    /api/sorvia/v1/servers/{id}/products         tek sunucudaki urunler</pre>
        <p class="text-muted small">
          Kimlik: <code>Authorization: Bearer &lt;jeton&gt;</code> ya da
          <code>X-Sorvia-Token: &lt;jeton&gt;</code>
        </p>
      </div>
    </div>
  </div>
</div>
