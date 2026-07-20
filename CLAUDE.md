# CLAUDE.md

Bu dosya, bu depoda çalışan geliştiricilere ve kod asistanlarına (Claude Code vb.) rehberlik eder.

## Proje Genel Bakış

**Lumen**, **LOGO Tiger ERP** veritabanı üzerinde çalışan web tabanlı bir **sipariş ve stok yönetim ön yüzüdür**. Satış terminali olarak tasarlanmıştır: sipariş girişi, stok/cari sorgulama, çek ve döviz işlemleri, raporlama. Verinin kaynağı LOGO'nun kendi SQL Server tablolarıdır; Lumen bu tablolar üzerine hızlı, mobil uyumlu bir arayüz koyar (LOGO bağımlılığı kalıcıdır).

- **Dil**: Türkçe (arayüz ve veritabanı içeriği dâhil)
- **Teknoloji**: PHP 8.0+, PDO (`sqlsrv` sürücüsü), Bootstrap 5 + Tailwind CSS, jQuery, Font Awesome
- **Veritabanı**: Microsoft SQL Server (LOGO Tiger)
- **Marka**: bordo `#6F1022`, font Montserrat (`--red` CSS değişkeni vurgu rengidir)

## Kurulum

İlk kurulum tarayıcıdan **`/kurulum.php`** sihirbazıyla yapılır:
1. Veritabanı bağlantısı (SQL Server + firma/dönem) → `.env` yazılır, `_baglanti_.inc` oluşturulur.
2. Yönetici hesabı (mevcut LOGO satış temsilcisi kodu + parola).

Tamamlanınca `.installed` kilit dosyası oluşur ve sihirbaz kapanır. Elle kurulum için `.env.example` → `.env` ve `_baglanti_.inc.example` → `_baglanti_.inc` kopyalanır.

## Veritabanı Mimarisi

### Bağlantı

`_baglanti_.inc` bağlantı değişkenlerini `.env`'den (getenv) okur:
- `AKL_DB_SERVER`, `AKL_DB_NAME`, `AKL_DB_USER`, `AKL_DB_PASS`
- Firma/dönem önekleri: `FIRMA_PREFIX` (`$firma`), `FIRMA_DONEM` (`$firmadonem`), `FIRMA_DONEM_VIEW` (`$firmadonemx`)

### LOGO Tablo Adlandırması

- `LG_XXX_` : dönemsiz firma tabloları — örn `LG_001_CLCARD` (cari), `LG_001_ITEMS` (stok)
- `LG_XXX_YY_` : dönemli tablolar — örn `LG_001_02_ORFICHE` (sipariş başlık), `ORFLINE` (satır)
- `LV_XXX_YY_` : hesaplanmış view'lar — `STINVTOT` (stok miktar), `GNTOTCL` (cari bakiye)
- `M_*` : Lumen'e ait özel tablolar (yetki, log, ayarlar). Şemaları `sql/` altındadır.

### Kritik Tablolar

- **CLCARD** cari, **ITEMS** stok, **ORFICHE/ORFLINE** sipariş, **CLFLINE** cari hareket
- **CSCARD/CSTRANS** çek, **KSLINES** kasa, **INVOICE** fatura
- **LV_..._STINVTOT** stok miktarı, **LV_..._GNTOTCL** cari bakiye
- **LG_SLSMAN** satış temsilcisi (giriş kullanıcısı), **M_P_YETKI** özel yetki tablosu

## Yetki Sistemi

Giriş: `LG_SLSMAN.CODE` (kullanıcı adı) ⋈ `M_P_YETKI.SIFRE` (parola, `password_hash` ile). Yetki seviyesi `M_P_YETKI.YETKI`:
- **0** Yönetici (tüm izinler otomatik açık)
- **1** Personel (kod bazlı izinler: `M1`..`Mn`, `ayar/yetki_tanimlari.php`)
- **2** Müşteri (personel paneline erişemez)

Kontrol: `m_p_yetki($terminalkullanici, 'M1') == 1`. Her korumalı sayfa `kontrol.php` içerir.

## Uygulama Yapısı

- **giris.php** giriş · **kontrol.php** oturum/yetki koruması · **ayr.php** global bootstrap + DB + yardımcılar · **index.php** ana pano
- **Yapılandırma**: `_baglanti_.inc` (bağlantı), `_bilgi_.inc` (özellik bayrakları + varsayılanlar), `.env` (gizli/ortam)
- **Sipariş**: `lg_fis.php`, `cari.php`, `stok_tara.php`, `lg_stok_bul.php`
- **Cari/Stok**: `lg_bakiye.php`, `cari_islemleri.php`, `lg_stok_ekle.php`
- **Çek/Kasa/Döviz**: `cek*.php`, `lg_nakit.php`, `doviz/`
- **Raporlar**: `rapor/`
- **Ayarlar**: `ayar/` (kullanıcı, yetki, sistem ayarları, `marka.php` logo yükleme)

## Önemli Yardımcı Fonksiyonlar (ayr.php)

`tlgoster`, `kusuratpara`, `kusuratadet` (biçimlendirme) · `cari_bul`, `stok_miktar_bul`, `bekleyen_siparis`, `birim_bul` · `m_p_yetki` (yetki) · `turkce`, `turkcearama` (Türkçe arama; `[ıi]`, `[cç]` desenleri) · `csrf_token`/`csrf_field`/`csrf_verify` (CSRF) · `sifre_hashle`/`sifre_dogrula` (parola).

## Yapılandırma ve Loglama

- **.env** ortam değişkenleri (`ayr.php::loadEnv`). Gerçek ortam değişkenleri .env'i ezer.
- **Loglama**: `includes/app_observability.php`. Log kökü `.env` `LOG_ROOT` yoksa `<proje>/logs`'a düşer (taşınabilir).
- **Özellik bayrakları** `_bilgi_.inc`'te: `$dovizlicalis`, `$cokludepo`, `$cek_beta`, `$magaza_cari` (0 = kapalı) vb.

## Marka

- Logo: `logo.png` (yatay wordmark), `icon.png` (kare uygulama ikonu), `favicon.ico`. Yönetici `ayar/marka.php`'den kendi logosunu yükleyebilir.
- Renk `#6F1022`, font Montserrat. Vurgu rengi `--red` CSS değişkeni; kullanıcı bazlı kişiselleştirme `pwa-header.php` + `M_USER_SETTINGS`.

## Geliştirme Komutları

```bash
composer install                         # bağımlılıklar
php -S localhost:8000 -t .               # yerel sunucu
php -l path/to/file.php                  # tek dosya sözdizim denetimi
bash scripts/validate.sh                 # lint + sağlık taraması
```
Otomatik test yok. Değişiklikten sonra: `validate.sh`, ardından `giris.php` + ilgili modülü elle doğrula.

## Kod Desenleri

Yeni PHP dosyalarında `declare(strict_types=1);`. Korumalı sayfa iskeleti:
```php
<?php
include_once(__DIR__ . '/ayr.php');
include(__DIR__ . '/kontrol.php');
```
Sorgular **daima** PDO prepared statement. Çıktıda `htmlspecialchars()`. Türkçe aramada `turkcearama()`. Yollar `__DIR__` tabanlı.
