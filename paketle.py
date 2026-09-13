#!/usr/bin/env python3
"""srv-pteroapi.blueprint paketini uretir.

Neden python: Windows'un Compress-Archive'i giris adlarini ters bolu ile
yaziyor ve Blueprint bunu Linux'ta okuyamiyor. Ayrica kabuk betikleri LF
satir sonuyla gitmeli, yoksa "bad interpreter: /bin/bash^M" ile oluyor.
"""

import io
import os
import sys
import zipfile

CIKTI = "srv-pteroapi.blueprint"

# Pakete girmeyecekler. Bunlar gelistirme dosyalari; panelde isleri yok.
ATLA_KLASOR = {".git", "data", "__pycache__"}
ATLA_DOSYA = {CIKTI, "srv-pteroapi.zip", ".gitignore", ".gitattributes",
              "paketle.sh", "paketle.py", "dogrula.py"}

# Satir sonu normalize edilecek uzantilar.
METIN = (".php", ".yml", ".sh", ".md", ".css", ".svg")


def paketle(kok: str) -> str:
    cikti = os.path.join(kok, CIKTI)
    if os.path.exists(cikti):
        os.remove(cikti)

    sayi = 0
    with zipfile.ZipFile(cikti, "w", zipfile.ZIP_DEFLATED) as z:
        for dizin, altlar, dosyalar in os.walk(kok):
            altlar[:] = [a for a in altlar if a not in ATLA_KLASOR]
            for d in sorted(dosyalar):
                if d in ATLA_DOSYA:
                    continue
                tam = os.path.join(dizin, d)
                goreli = os.path.relpath(tam, kok).replace(os.sep, "/")
                if d.endswith(METIN):
                    with io.open(tam, "r", encoding="utf-8", newline="") as f:
                        icerik = f.read().replace("\r\n", "\n").replace("\r", "\n")
                    bilgi = zipfile.ZipInfo(goreli)
                    bilgi.external_attr = (0o755 if d.endswith(".sh") else 0o644) << 16
                    z.writestr(bilgi, icerik.encode("utf-8"))
                else:
                    z.write(tam, goreli)
                sayi += 1
    return f"{CIKTI} uretildi ({sayi} dosya)"


if __name__ == "__main__":
    print(paketle(os.path.dirname(os.path.abspath(__file__)) or "."))
    sys.exit(0)
