#!/usr/bin/env bash
#
# Güvenlik açısından kritik, çerçevesiz akışlar için odaklı regresyon kapıları.
# Bu kontroller gerçek entegrasyon testlerinin yerini almaz; bilinen zayıf
# kalıpların yeniden eklenmesini CI aşamasında engeller.

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

hata=0

basarisiz() {
  printf 'HATA: %s\n' "$1" >&2
  hata=1
}

zorunlu() {
  local desen="$1"
  local dosya="$2"
  local mesaj="$3"
  grep -Fq -- "$desen" "$dosya" || basarisiz "$mesaj"
}

yasak() {
  local desen="$1"
  local dosya="$2"
  local mesaj="$3"
  if grep -Fq -- "$desen" "$dosya"; then
    basarisiz "$mesaj"
  fi
}

# EAN aktarımı durum değiştiren bir işlemdir: GET'ten POST taklidi yapılamaz.
yasak "\$_GET['ean']" barkod/barkod_ekle.php \
  "Barkod ekleme tekrar GET ean parametresini kabul ediyor."
yasak "\$_SERVER['REQUEST_METHOD'] = 'POST'" barkod/barkod_ekle.php \
  "Sunucu istek yöntemi uygulama kodunda değiştiriliyor."
zorunlu '<form id="transferForm" method="post" action="barkod_ekle.php"' barkod/ean13.php \
  "EAN aktarımı POST formu kullanmıyor."
zorunlu '<?php echo csrf_field(); ?>' barkod/ean13.php \
  "EAN aktarım formunda CSRF alanı yok."
zorunlu 'name="stok"' barkod/ean13.php \
  "EAN aktarımı stok kimliğini POST gövdesinde taşımıyor."
zorunlu "document.getElementById('transferForm').requestSubmit();" barkod/ean13.php \
  "EAN aktarım düğmesi güvenli formu göndermiyor."
zorunlu "m_p_yetki(\$terminalkullanici, 'M6')" barkod/ean13.php \
  "EAN üretici M6 barkod yetkisini doğrulamıyor."

# Temiz kurulumda brute-force kontrollerinin ihtiyaç duyduğu şema bulunmalı.
zorunlu "CREATE TABLE dbo.M_GIRIS_LOG" sql/m_giris_log.sql \
  "M_GIRIS_LOG kurulum şeması eksik."
zorunlu "kurulum_sema_uygula(\$test, \$KOK . '/sql', \$onek)" kurulum.php \
  "Kurulum sihirbazı sql/ şemalarını otomatik uygulamıyor."
for kolon in KULLANICI_ID KULLANICI_ADI ISLEM_TIPI BASARILI IP_ADRESI TARAYICI TARIH ACIKLAMA; do
  zorunlu "$kolon" sql/m_giris_log.sql \
    "M_GIRIS_LOG şemasında ${kolon} kolonu eksik."
done

# Action bağımlılıkları değişebilir etiket/branch yerine tam commit'e sabitlenmeli.
while IFS= read -r satir; do
  eylem="${satir#*uses:}"
  eylem="${eylem#"${eylem%%[![:space:]]*}"}"
  eylem="${eylem%%[[:space:]#]*}"
  if [[ "$eylem" == ./* || "$eylem" == docker://* ]]; then
    continue
  fi
  ref="${eylem##*@}"
  if [[ ! "$ref" =~ ^[0-9a-f]{40}$ ]]; then
    basarisiz "GitHub Action tam commit SHA'sına sabitlenmemiş: ${eylem}"
  fi
done < <(grep -REh '^[[:space:]]*-[[:space:]]+uses:' .github/workflows)

if [[ "$hata" -ne 0 ]]; then
  exit "$hata"
fi

echo "Güvenlik regresyon kontrolleri geçti."
