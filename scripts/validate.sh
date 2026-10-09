#!/usr/bin/env bash
#
# validate.sh — Lumen doğrulama betiği
#
# Sert kapı (hata verirse çıkış kodu 1):
#   - PHP sözdizimi (php -l) tüm dosyalarda temiz olmalı
#   - Composer kilidi geçerli ve üretim bağımlılıkları güvenli olmalı (composer varsa)
#   - Güvenlik regresyon kontrolleri geçmeli
#
# Bilgilendirme (yalnız sayı basar, başarısız etmez):
#   Aşağıdaki ölçümler kod tabanının gidişatını göstermek içindir.
#   Sıfır olmaları beklenmez; büyük artışlar gözden geçirilmelidir.
#
# Kullanım:  bash scripts/validate.sh
#            SKIP_RECTOR=1 bash scripts/validate.sh   (Rector adımını atla)

set -uo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

hata=0

# ---------------------------------------------------------------- PHP lint --
echo "== PHP Sözdizimi (php -l) =="

lint_hata=0
lint_toplam=0
while IFS= read -r -d '' dosya; do
  lint_toplam=$((lint_toplam + 1))
  if ! php -l "$dosya" >/dev/null 2>&1; then
    echo "  HATA: $dosya"
    php -l "$dosya" 2>&1 | head -3 | sed 's/^/        /'
    lint_hata=$((lint_hata + 1))
  fi
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' -print0)

echo "  Taranan: $lint_toplam dosya · Hatalı: $lint_hata"
if [[ "$lint_hata" -ne 0 ]]; then
  echo "BAŞARISIZ: PHP sözdizimi hatası var" >&2
  hata=1
else
  echo "TAMAM: PHP lint"
fi

# ------------------------------------------------------------ composer.json --
echo
echo "== composer.json =="
if command -v composer >/dev/null 2>&1; then
  if composer validate --strict --no-check-publish --quiet; then
    echo "TAMAM: composer.json ve composer.lock geçerli"
  else
    echo "BAŞARISIZ: Composer yapılandırması/kilidi doğrulanamadı" >&2
    hata=1
  fi

  if composer install --no-dev --dry-run --no-interaction --no-scripts --quiet; then
    echo "TAMAM: üretim bağımlılıkları mevcut PHP platformuyla uyumlu"
  else
    echo "BAŞARISIZ: üretim bağımlılıkları mevcut PHP platformuyla uyumlu değil" >&2
    hata=1
  fi

  if composer audit --locked --no-dev --quiet; then
    echo "TAMAM: üretim bağımlılıklarında bilinen güvenlik açığı yok"
  else
    echo "BAŞARISIZ: üretim bağımlılıklarında güvenlik uyarısı var" >&2
    hata=1
  fi
else
  echo "ATLANDI: composer kurulu değil"
fi

# --------------------------------------------------- güvenlik regresyonları --
echo
echo "== Güvenlik regresyonları =="
if bash scripts/security-regression.sh; then
  echo "TAMAM: güvenlik regresyonları"
else
  echo "BAŞARISIZ: güvenlik regresyonu bulundu" >&2
  hata=1
fi

# --------------------------------------------------- davranış regresyonları --
echo
echo "== Yardımcı ve API davranış regresyonları =="
if ! php tests/run.php || ! php tests/permission-regression.php || ! php tests/api-regression.php || ! php tests/idempotency-regression.php || ! php tests/web-intent-regression.php || ! php tests/ci-prepare-regression.php; then
  hata=1
fi

# ------------------------------------------------------- bilgilendirme ------
echo
echo "== Ölçümler (bilgi amaçlı — başarısız etmez) =="

say() {
  # $1: etiket, $2: grep deseni
  local sayi
  sayi="$(grep -rlE "$2" --include='*.php' --exclude-dir=vendor --exclude-dir=.git . 2>/dev/null | wc -l | tr -d ' ')"
  printf '  %-42s %s dosya\n' "$1" "$sayi"
}

say "->query() kullanan (parametresiz sorgu):" '\->query[[:space:]]*\('
say "@ ile bastırılmış dosya işlemi:" '@(file_put_contents|mkdir|filemtime|touch|unlink|fopen|copy|rename|chmod|chown)'

strict_eksik=0
while IFS= read -r -d '' dosya; do
  grep -q 'declare(strict_types=1)' "$dosya" || strict_eksik=$((strict_eksik + 1))
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' -print0)
printf '  %-42s %s dosya\n' "declare(strict_types=1) eksik:" "$strict_eksik"

# ------------------------------------------------------------------ Rector --
echo
echo "== Rector (dry-run) =="
if [[ "${SKIP_RECTOR:-0}" == "1" ]]; then
  echo "ATLANDI: SKIP_RECTOR=1"
elif [[ -f "vendor/bin/rector" ]]; then
  php vendor/bin/rector process --dry-run || echo "UYARI: Rector önerileri var (engelleyici değil)"
else
  echo "ATLANDI: vendor/bin/rector yok (composer install çalıştırın)"
fi

echo
if [[ "$hata" -eq 0 ]]; then
  echo "SONUÇ: TAMAM"
else
  echo "SONUÇ: BAŞARISIZ" >&2
fi
exit "$hata"
