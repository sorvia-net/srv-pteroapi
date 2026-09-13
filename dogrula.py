#!/usr/bin/env python3
"""Paketi, Blueprint'in kurulumda yaptigi kontrollerin aynisindan gecirir.

Bunu yazma sebebi: "blueprint -install" yol hatasi verdiginde
`Extension configuration points towards one or more files that do not exist`
diyor ve **hangi yolun eksik oldugunu soylemiyor**. Ayni kontrolu burada
yapinca eksigi sunucuya dokunmadan, adiyla goruyoruz.

Kontroller scripts/commands/extensions/install.sh kaynagindan alindi.
"""

import os
import re
import sys
import zipfile
import tempfile

PAKET = "srv-pteroapi.blueprint"
TERS_BOLU = chr(92)

DOSYA_OLMALI = [
    "info.icon", "admin.view", "admin.controller", "admin.css", "admin.wrapper",
    "dashboard.css", "dashboard.wrapper", "requests.routers.application",
    "requests.routers.client", "requests.routers.web",
]
KLASOR_OLMALI = [
    "dashboard.components", "data.directory", "data.public", "data.console",
    "requests.views", "requests.app", "database.migrations",
]
ZORUNLU = [
    "info.name", "info.identifier", "info.description", "info.version",
    "info.target", "admin.view",
]
ESKIMIS_ANAHTAR = ["requests.controllers"]
ESKIMIS_BAYRAK = ["hasInstallScript"]


def conf_oku(dosya: str) -> dict:
    """conf.yml'yi okur. Tam bir YAML ayristiricisi degil; bu dosyada
    duz anahtar/deger ve en fazla uc seviye ic ice yapi var."""
    conf: dict = {}
    yol: list = []
    with open(dosya, encoding="utf-8") as f:
        for ham in f:
            s = ham.rstrip("\n")
            if not s.strip() or s.strip().startswith("#"):
                continue
            seviye = (len(s) - len(s.lstrip())) // 2
            m = re.match(r"\s*([A-Za-z_]+):\s*(.*)$", s)
            if not m:
                continue
            ad, deger = m.group(1), m.group(2).strip().strip('"')
            yol = yol[:seviye] + [ad]
            if deger:
                conf[".".join(yol)] = deger
    return conf


def dogrula(paket: str) -> int:
    if not os.path.exists(paket):
        print(f"  {paket} yok. Once paketle.py calistirin.")
        return 1

    tmp = tempfile.mkdtemp()
    with zipfile.ZipFile(paket) as z:
        adlar = z.namelist()
        z.extractall(tmp)

    ters = [a for a in adlar if TERS_BOLU in a]
    if ters:
        print(f"  giris adlarinda ters bolu var ({len(ters)} adet) —"
              " Blueprint bunu Linux'ta okuyamaz")

    conf_yolu = os.path.join(tmp, "conf.yml")
    if not os.path.isfile(conf_yolu):
        print("  pakette conf.yml yok")
        return 1

    conf = conf_oku(conf_yolu)
    hata = len(ters)

    for k in ZORUNLU:
        if not conf.get(k):
            print(f"  eksik zorunlu alan: {k}")
            hata += 1

    for k in DOSYA_OLMALI:
        v = conf.get(k)
        if v and not os.path.isfile(os.path.join(tmp, v)):
            print(f"  yok (dosya olmali): {k} -> {v}")
            hata += 1

    for k in KLASOR_OLMALI:
        v = conf.get(k)
        if v and not os.path.isdir(os.path.join(tmp, v)):
            print(f"  yok (klasor olmali): {k} -> {v}")
            hata += 1

    kimlik = conf.get("info.identifier", "")
    if not re.fullmatch(r"[a-z]{1,48}", kimlik):
        print(f"  identifier yalnizca a-z olmali, en fazla 48 karakter: {kimlik}")
        hata += 1

    for k in DOSYA_OLMALI + KLASOR_OLMALI:
        v = conf.get(k)
        if v and (v.startswith("/") or ".." in v or TERS_BOLU in v or v.endswith("/")):
            print(f"  gecersiz yol bicimi: {k} -> {v}")
            hata += 1

    for k in ESKIMIS_ANAHTAR:
        if k in conf:
            print(f"  eskimis anahtar: {k}")
            hata += 1
    for b in ESKIMIS_BAYRAK:
        if b in conf.get("info.flags", ""):
            print(f"  eskimis bayrak: {b}")
            hata += 1

    print(f"  surum {conf.get('info.version')}, hedef Blueprint"
          f" {conf.get('info.target')}, {len(adlar)} dosya")
    print()
    if hata:
        print(f"SONUC: {hata} sorun")
    else:
        print("SONUC: gecti — kurulumun yol ve alan kontrollerini asar")
    return 1 if hata else 0


if __name__ == "__main__":
    kok = os.path.dirname(os.path.abspath(__file__)) or "."
    sys.exit(dogrula(os.path.join(kok, PAKET)))
