#!/usr/bin/env bash
# .blueprint paketini uretir ve dogrular.
#
# Is paketle.py ve dogrula.py icinde. Kabuk tarafinda heredoc yok: Windows
# uzerinde "python3" bir Store kisayoluna bagli ve stdin okurken takiliyor.
set -euo pipefail
cd "$(dirname "$0")"

PY=""
for aday in python3 python py; do
  if command -v "$aday" >/dev/null 2>&1 && "$aday" -c "import sys" >/dev/null 2>&1; then
    PY="$aday"
    break
  fi
done

if [[ -z "$PY" ]]; then
  echo "python bulunamadi." >&2
  exit 1
fi

"$PY" paketle.py
"$PY" dogrula.py
