#!/usr/bin/env bash
# .blueprint paketini uretir.
#
# Windows'un Compress-Archive'i giris adlarini ters bolu ile yaziyor ve
# Linux'ta Blueprint o yollari okuyamiyor; bu yuzden paket python ile
# uretiliyor. Kabuk betikleri LF ile gidiyor: CRLF ile giden bir install.sh
# "bad interpreter: /bin/bash^M" ile patlar.
set -euo pipefail
cd "$(dirname "$0")"
python - <<'PY'
import os, zipfile, io
kok = os.getcwd()
cikti = os.path.join(kok, "srv-pteroapi.blueprint")
ATLA = {".git", "data", "__pycache__"}
ATLA_DOSYA = {"srv-pteroapi.blueprint", "srv-pteroapi.zip", ".gitignore", ".gitattributes", "paketle.sh"}
METIN = (".php", ".yml", ".sh", ".md", ".css", ".svg")

with zipfile.ZipFile(cikti, "w", zipfile.ZIP_DEFLATED) as z:
    for dizin, altlar, dosyalar in os.walk(kok):
        altlar[:] = [a for a in altlar if a not in ATLA]
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
print("srv-pteroapi.blueprint uretildi")
PY
