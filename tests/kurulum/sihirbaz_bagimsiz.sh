#!/usr/bin/env bash
# Kurulum sihirbazı uçtan uca (bağımsız mod): boş sunucuda veritabanını oluşturur,
# şemayı kurar, yöneticiyi yazar; yönetici girer ve bağımsız modüller menüde görünür.
#
# Uygulama konteynerinde çalışır; deponun geçici bir kopyasını ortam değişkenleri
# OLMADAN ayrı bir PHP sunucusunda açar (sihirbaz yapılandırılmış sistemde kapanır;
# depodaki .env'e dokunulmaz):
#   docker compose -f demo/docker-compose.yml exec -T app bash tests/kurulum/sihirbaz_bagimsiz.sh
set -euo pipefail

KOK=$(cd "$(dirname "$0")/../.." && pwd)
DB=LUMEN_KURULUM_TEST
KOPYA=$(mktemp -d)
CEREZ=$(mktemp)
PORT=8099
URL="http://127.0.0.1:${PORT}"
hata() { echo "  ✗ $*"; exit 1; }

# Önceki çalıştırmadan kalan test veritabanını kaldır (yalnız bu sabit ad).
DB="$DB" php <<'PHP'
<?php
$db = getenv('DB');
$p = new PDO('sqlsrv:server=' . getenv('AKL_DB_SERVER') . ';database=master;TrustServerCertificate=1', getenv('AKL_DB_USER'), getenv('AKL_DB_PASS'));
$p->exec("IF DB_ID(N'{$db}') IS NOT NULL BEGIN ALTER DATABASE [{$db}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [{$db}]; END");
PHP

(cd "$KOK" && tar --exclude=./logs --exclude=./tmp --exclude=./.git -cf - .) | tar -xf - -C "$KOPYA"
rm -f "$KOPYA/.env" "$KOPYA/.installed" "$KOPYA/_baglanti_.inc"
mkdir -p "$KOPYA/logs/app" "$KOPYA/logs/php"
env -i PATH="$PATH" php -S "127.0.0.1:${PORT}" -t "$KOPYA" > /dev/null 2>&1 &
SUNUCU=$!
trap 'kill $SUNUCU 2>/dev/null; rm -rf "$KOPYA" "$CEREZ"' EXIT
sleep 1

echo "Kurulum sihirbazı (bağımsız mod)"
CSRF=$(curl -s -c "$CEREZ" -b "$CEREZ" "$URL/kurulum.php" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
[ -n "$CSRF" ] || hata "sihirbaz açılmadı"
G=$(curl -s -c "$CEREZ" -b "$CEREZ" "$URL/kurulum.php" --data-urlencode "csrf=$CSRF" -d adim=1 -d mod=lumen \
    -d db_server="$AKL_DB_SERVER" -d db_name="$DB" --data-urlencode "db_user=$AKL_DB_USER" --data-urlencode "db_pass=$AKL_DB_PASS" \
    --data-urlencode "firma_baslik=Kurulum Testi A.Ş." -d magaza_cari=0)
echo "$G" | grep -q 'veritabanı oluşturuldu' || hata "veritabanı oluşturulmadı: $(echo "$G" | grep -o 'mesaj err.\{0,300\}')"
echo "$G" | grep -q 'Lumen veritabanı hazırlandı' || hata "şema kurulmadı: $(echo "$G" | grep -o 'mesaj err.\{0,300\}')"
grep -q '^LUMEN_VERI_KAYNAGI=lumen$' "$KOPYA/.env" || hata ".env bağımsız modu yazmadı"
echo "  ✓ adım 1: veritabanı oluşturuldu, şema kuruldu, .env bağımsız"

G=$(curl -s -c "$CEREZ" -b "$CEREZ" "$URL/kurulum.php" --data-urlencode "csrf=$CSRF" -d adim=2 -d admin_kod=patron \
    --data-urlencode "admin_ad=Patron Kişi" -d admin_pass=Parola-1234 -d admin_pass2=Parola-1234)
echo "$G" | grep -q 'Kurulum Tamamlandı' || hata "yönetici oluşturulamadı: $(echo "$G" | grep -o 'mesaj err.\{0,300\}')"
[ -f "$KOPYA/.installed" ] || hata "kilit dosyası yok"
echo "  ✓ adım 2: yönetici LM_PERSONEL + M_P_YETKI"

rm -f "$CEREZ"
curl -s -c "$CEREZ" -b "$CEREZ" "$URL/giris.php" > /dev/null
KOD=$(curl -s -o /dev/null -w '%{http_code}' -c "$CEREZ" -b "$CEREZ" "$URL/giris.php" -d kullaniciadi=PATRON -d kullanicisifre=Parola-1234)
[ "$KOD" = 302 ] || hata "yönetici giremedi (HTTP $KOD)"
ANA=$(curl -s -c "$CEREZ" -b "$CEREZ" "$URL/index.php")
for m in belge/index.php finans/index.php cari/cari.php; do
    echo "$ANA" | grep -q "href=\"$m" || hata "menüde $m yok"
done
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/kurulum.php")" = 302 ] || hata "sihirbaz kurulumdan sonra açık kaldı"
echo "  ✓ yönetici girer; faturalar, kasa/banka ve cari menüde; sihirbaz kapandı"
