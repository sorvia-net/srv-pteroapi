#!/usr/bin/env bash
# .blueprint paketini uretir.
#
# Giris adlari duz bolu ile yazilmali ve kabuk betikleri LF satir sonuyla
# gitmeli; Windows'un Compress-Archive'i ikisini de bozuyor. Once `zip`
# denenir (Linux'ta hazir), yoksa python'a dusulur.
set -euo pipefail
cd "$(dirname "$0")"

CIKTI="srv-pteroapi.blueprint"
rm -f "$CIKTI"

HARIC=( -x ".git/*" "data/*" "*.zip" "$CIKTI" ".gitignore" ".gitattributes" "paketle.sh" )

if command -v zip >/dev/null 2>&1; then
  zip -q -r "$CIKTI" . "${HARIC[@]}"
  echo "$CIKTI uretildi (zip)"
  exit 0
fi

python3 - <<'PY' || python - <<'PY'
import os, zipfile, io
kok = os.getcwd()
cikti = os.path.join(kok, "srv-pteroapi.blueprint")
ATLA = {".git", "data", "__pycache__"}
ATLA_DOSYA = {"srv-pteroapi.blueprint", "srv-pteroapi.zip", ".gitignore",
              ".gitattributes", "paketle.sh"}
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
print("srv-pteroapi.blueprint uretildi (python)")
PY
