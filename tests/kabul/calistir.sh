#!/usr/bin/env bash
# Lumen kabul testi — tek komut. Temiz bir klondan çalışır: demoyu iki modda kurar, bütün
# test takımlarını, "iş günü" senaryosunu ve sunucu denetimini koşar; sonucu Markdown
# rapora yazar. Yapay zekâ ajanı ya da insan, aynı komutu çalıştırır (docs/test/KABUL_TESTI.md).
#
#   bash tests/kabul/calistir.sh                 # rapor: kabul-raporu.md
#   bash tests/kabul/calistir.sh --rapor yol.md
#
# Gereksinim: Docker + docker compose (Docker Desktop, Colima ya da OrbStack), ~6 GB bellek.
# Çıkış kodu: 0 hepsi geçti, 1 en az bir adım başarısız.
set -uo pipefail

KOK=$(cd "$(dirname "$0")/../.." && pwd)
cd "$KOK"
RAPOR="$KOK/kabul-raporu.md"
while [ $# -gt 0 ]; do
  case "$1" in
    --rapor) RAPOR="$2"; shift 2 ;;
    *) echo "Bilinmeyen seçenek: $1" >&2; exit 2 ;;
  esac
done
GECICI=$(mktemp -d)
trap 'rm -rf "$GECICI"' EXIT
uyg() { docker compose -f demo/docker-compose.yml exec -T app "$@"; }

ADIMLAR=()      # "durum|ad|süre|günlük dosyası"
BASARISIZ=0
SINIR=${KABUL_ADIM_SINIRI:-1200}   # adım başına süre sınırı (sn); takılan adım durdurulur
# Süre sınırı: GNU timeout her sistemde yok (macOS); perl her ikisinde de var.
sinirli() { local sn="$1"; shift; perl -e 'my $sn = shift @ARGV; my $pid = fork() // die "fork"; if (!$pid) { exec { $ARGV[0] } @ARGV; exit 127 } local $SIG{ALRM} = sub { kill "TERM", $pid; sleep 2; kill "KILL", $pid; exit 124 }; alarm $sn; waitpid($pid, 0); exit($? & 127 ? 1 : $? >> 8)' "$sn" "$@"; }
adim() {   # adim "Ad" komut...  (kurulum adımlarında sınır 1800 sn)
  local ad="$1"; shift
  local gunluk="$GECICI/adim_${#ADIMLAR[@]}.log" bas=$SECONDS durum kod sn="$SINIR"
  case "$ad" in *kurulumu*) sn=1800 ;; esac
  printf '\n▶ %s\n' "$ad"
  if declare -F "$1" >/dev/null; then   # kabuk işlevi (uyg): alt kabukta, işlev dışa aktarılarak
    export -f "$1"
    sinirli "$sn" bash -c '"$@"' _ "$@" >"$gunluk" 2>&1; kod=$?
  else
    sinirli "$sn" "$@" >"$gunluk" 2>&1; kod=$?
  fi
  if [ "$kod" -eq 0 ]; then durum=TAMAM; else durum=HATA; BASARISIZ=$((BASARISIZ + 1)); fi
  if [ "$kod" -eq 124 ]; then
    echo "ZAMAN AŞIMI: adım ${sn} sn içinde bitmedi ve durduruldu." >>"$gunluk"
  fi
  if [ "$kod" -ne 0 ]; then   # teşhis: konteyner durumu ve veritabanı günlüğünün sonu
    { echo; echo "--- docker compose ps ---"; docker compose -f demo/docker-compose.yml ps 2>&1
      echo "--- SQL Server günlüğü (son 15 satır) ---"; docker compose -f demo/docker-compose.yml logs --tail=15 db 2>&1; } >>"$gunluk"
  fi
  tail -n 25 "$gunluk" | sed 's/^/    /'
  ADIMLAR+=("$durum|$ad|$((SECONDS - bas))|$gunluk")
  printf '  → %s (%ss)\n' "$durum" "$((SECONDS - bas))"
}

command -v docker >/dev/null || { echo "Docker bulunamadı." >&2; exit 2; }
docker compose version >/dev/null 2>&1 || { echo "docker compose bulunamadı." >&2; exit 2; }

BASLANGIC=$(date '+%Y-%m-%d %H:%M')
SURUM=$(git describe --tags --always 2>/dev/null || echo "?")
COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "?")

# ---- LOGO'ya bağlı mod (kararlı sürümün modu)
adim "LOGO modu: demo kurulumu" bash demo/kur.sh
adim "LOGO modu: şema güncelleme iki kez (mevcut kurulum)" bash -c 'docker compose -f demo/docker-compose.yml exec -T app php scripts/sema_guncelle.php && docker compose -f demo/docker-compose.yml exec -T app php scripts/sema_guncelle.php'
adim "LOGO modu: birim testleri" uyg php tests/birim/calistir.php
adim "LOGO modu: duman testi (tüm sayfalar × 3 kullanıcı)" uyg php tests/duman/tara.php
adim "LOGO modu: akış testleri" uyg php tests/akis/akislar.php
adim "LOGO modu: yedekleme gidiş-dönüş" uyg php tests/yedek/yedek_test.php
adim "LOGO'dan bağımsız Lumen'e içe aktarma" uyg php tests/aktarim/logo_aktar_test.php

# ---- Bağımsız mod (beta)
if [ -d belge ] && [ -f tests/akis/bagimsiz.php ]; then
  adim "Bağımsız mod: demo kurulumu" bash demo/kur.sh --bagimsiz
  adim "Bağımsız mod: duman testi" uyg php tests/duman/tara.php
  adim "Bağımsız mod: akış testleri" uyg php tests/akis/bagimsiz.php
  [ -f tests/akis/guvenlik.php ] && adim "Bağımsız mod: güvenlik regresyonları" uyg php tests/akis/guvenlik.php
  adim "Bağımsız mod: LOGO akışları görünümler üzerinden" uyg php tests/akis/akislar.php
  adim "Bağımsız mod: yedekleme gidiş-dönüş" uyg php tests/yedek/yedek_test.php
  adim "Kurulum sihirbazı uçtan uca (boş veritabanı)" uyg bash tests/kurulum/sihirbaz_bagimsiz.sh
  adim "İş günü senaryosu (ekranlar arası tutarlılık, yedekten dönüş)" uyg php tests/kabul/is_gunu.php
fi
adim "Sunucu denetimi (gizli dosyalar, başlıklar, PHP sürümü)" \
  docker compose -f demo/docker-compose.yml exec -T -e LUMEN_DENETIM_PAROLA=demo1234 app \
  php tests/kabul/sunucu_denetimi.php http://web --kullanici YONETICI --rapor /tmp/sunucu_denetimi.md
adim "Statik güvenlik regresyonları" bash scripts/security-regression.sh
PHP_SURUM=$(uyg php -r 'echo PHP_VERSION;' 2>/dev/null || echo "?")

# ---- Rapor
{
  echo "# Lumen kabul testi raporu"
  echo
  echo "| | |"
  echo "|---|---|"
  echo "| Sürüm | \`$SURUM\` (commit \`$COMMIT\`) |"
  echo "| Başlangıç | $BASLANGIC |"
  echo "| Ortam | $(uname -sm), Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo '?'), PHP $PHP_SURUM |"
  echo "| Sonuç | $([ "$BASARISIZ" -eq 0 ] && echo '✅ **Bütün adımlar geçti**' || echo "❌ **$BASARISIZ adım başarısız**") |"
  echo
  echo "## Adımlar"
  echo
  echo "| Sonuç | Adım | Süre |"
  echo "|---|---|---|"
  for a in "${ADIMLAR[@]}"; do
    IFS='|' read -r d ad sure _ <<<"$a"
    echo "| $([ "$d" = TAMAM ] && echo ✅ || echo ❌) | $ad | ${sure} sn |"
  done
  for a in "${ADIMLAR[@]}"; do
    IFS='|' read -r d ad sure gunluk <<<"$a"
    if [ "$d" = HATA ]; then
      echo
      echo "### ❌ $ad"
      echo
      echo '```'
      tail -n 60 "$gunluk"
      echo '```'
    fi
  done
  echo
  docker compose -f demo/docker-compose.yml exec -T app cat /tmp/sunucu_denetimi.md 2>/dev/null || true
} >"$RAPOR"

echo
echo "Rapor: $RAPOR"
[ "$BASARISIZ" -eq 0 ] && echo "SONUÇ: bütün adımlar geçti" || echo "SONUÇ: $BASARISIZ adım başarısız"
[ "$BASARISIZ" -eq 0 ]
