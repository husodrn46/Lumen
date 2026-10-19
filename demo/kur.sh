#!/usr/bin/env bash
#
# Lumen demo ortamını kurar: SQL Server + sentetik LOGO verisi + uygulama.
#
#   bash demo/kur.sh             # kur / güncelle — bağlı mod, LOGO demo şeması (veri sıfırlanır)
#   bash demo/kur.sh --bagimsiz  # bağımsız mod: Lumen'in kendi LM_ şeması, LOGO yok
#   bash demo/kur.sh --kapat  # konteynerleri durdur (veri saklanır)
#   bash demo/kur.sh --sil    # konteynerleri ve demo verisini tamamen sil
#
# Gereksinim: Docker (Docker Desktop, Colima veya OrbStack) ve docker compose.
set -euo pipefail

DEMO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
KOK="$(cd -- "$DEMO_DIR/.." && pwd)"
cd "$DEMO_DIR"

SA_PASS="${LUMEN_DEMO_SA_PASS:-Lumen_Demo_2026!}"
DB_ADI="LUMEN_DEMO"
MOD="logo"
if [ "${1:-}" = "--bagimsiz" ]; then
  # Bağımsız mod: LOGO şeması yok; Lumen'in kendi LM_ tabloları (sql/lumen_cekirdek/).
  MOD="lumen"
  DB_ADI="LUMEN_BAGIMSIZ"
  shift
fi
export LUMEN_VERI_KAYNAGI="$MOD" LUMEN_DEMO_DB="$DB_ADI"
PORT="${LUMEN_DEMO_PORT:-8088}"

case "${1:-}" in
  --kapat) docker compose stop; exit 0 ;;
  --sil)   docker compose down -v; exit 0 ;;
esac

sqlcmd() {
  docker compose exec -T db /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P "$SA_PASS" -b -I "$@"
}

echo "==> Konteynerler başlatılıyor (ilk seferde imajlar iner, birkaç dakika sürebilir)"
docker compose up -d --build

echo "==> SQL Server bekleniyor"
for _ in $(seq 1 60); do
  if sqlcmd -Q "SELECT 1" >/dev/null 2>&1; then break; fi
  sleep 3
done
sqlcmd -Q "SELECT 1" >/dev/null

echo "==> Veritabanı: $DB_ADI (sıfırdan)"
sqlcmd -Q "IF DB_ID('$DB_ADI') IS NOT NULL BEGIN ALTER DATABASE [$DB_ADI] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$DB_ADI]; END"
sqlcmd -Q "CREATE DATABASE [$DB_ADI] COLLATE Turkish_CI_AS"

uygula() {
  # Kurulum sihirbazıyla aynı yöntem: GO'dan böl, PDO ile çalıştır.
  for dosya in "$@"; do printf '    %s\n' "$dosya"; done
  docker compose exec -T app php demo/araclar/sql_uygula.php "$DB_ADI" "$@"
}

if [ "$MOD" = "logo" ]; then
  echo "==> LOGO şeması (demo)"
  uygula demo/sql/01_logo_sema.sql
fi

echo "==> Lumen şema betikleri (sql/ — kurulum sihirbazının uyguladıklarıyla aynı)"
docker compose exec -T app php demo/araclar/sql_uygula.php "$DB_ADI" --sema

echo "==> Demo verisi ($MOD)"
if [ "$MOD" = "logo" ]; then
  uygula demo/sql/02_demo_veri.sql
else
  uygula demo/sql/bagimsiz/01_demo_veri.sql
fi

echo "==> Uygulama bekleniyor (konteyner ilk açılışta composer install çalıştırır)"
# 'docker compose exec' adımları PHP-FPM'i beklemez; web istekleri bekler. Hazır
# olmadan dönülürse ilk HTTP istekleri 502 alır (CI'daki duman testi gibi).
hazir=""
for _ in $(seq 1 90); do
  kod=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/giris.php" || true)
  if [ "$kod" = "200" ]; then hazir=1; break; fi
  sleep 2
done
if [ -z "$hazir" ]; then
  echo "HATA: uygulama 180 sn içinde yanıt vermedi (son HTTP kodu: ${kod:-yok})" >&2
  docker compose logs --tail=50 app web >&2
  exit 1
fi
echo
echo "Hazır ($MOD modu, veritabanı $DB_ADI): http://localhost:$PORT/giris.php"
echo "Kullanıcılar: YONETICI / SATIS1 / DEPO1 — parola: demo1234"
