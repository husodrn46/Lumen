# Bağımsız Lumen — mimari ve yol haritası

> **Durum: BETA** — son ön sürüm `v1.3.0-beta.3` (ilki `v1.3.0-beta.1`). Kararlı sürüm (v1.2.1) yalnız LOGO'ya bağlı moddur.

**Hedef:** Lumen tek başına çalışan bir ön muhasebe programı olur (kendi veritabanı,
cari, stok, sipariş, irsaliye, KDV'li fatura, kasa/banka, çek/senet, raporlar) ve
**ek olarak** hazır veritabanlarına (önce LOGO Tiger; ileride Netsis, Mikro, ETA)
bağlanabilir.

Kararlar (2026-10-19):

| Konu | Karar |
|---|---|
| Bağlı mod | **İki mod ayrı.** Bağımsız modda asıl kayıt Lumen veritabanında; bağlı modda bugünkü gibi doğrudan LOGO'da (Lumen ön yüz). Senkronizasyon yok. |
| Muhasebe kapsamı | **Önce ön muhasebe.** Resmi muhasebe (hesap planı, yevmiye, mizan, KDV beyannamesi, e-defter) sonraki aşama. |
| e-Belge | **Sonraki aşama.** Mimari hazır tasarlanır; GİB/özel entegratör ilk sürümde yok. |

## 1. Bugünkü durum (ölçüm, 2026-10-19)

- 256 PHP dosyasının **181'i** LOGO tablolarına doğrudan SQL yazar (`{$firma}CLCARD`,
  `{$firmadonem}ORFICHE` …); `$firma` ≈606, `$firmadonem` ≈761 kullanım.
- 88 farklı LOGO tablosu/view'ı; 27+ dosya LOGO'ya yazar.
- **Kimlik LOGO'dadır:** giriş `LG_SLSMAN` + `M_P_YETKI` ile yapılır; oturumdaki
  `plasiyer_id` siparişlerin `SALESMANREF`'idir. Tüm `M_` tablolarının kullanıcı
  kolonları `LG_SLSMAN.LOGICALREF`'e bakar.
- LOGO'ya özgü mekanikler: `LV_..._GNTOTCL` (cari bakiye), `LV_..._STINVTOT` (stok
  miktarı) view'ları; tetikleyiciler (STLINE → ORFLINE.SHIPPEDAMOUNT); "şablon satırı
  klonla" yöntemi (fatura, çek, cari, üretim); `L_DAILYEXCHANGES` kurları.
- Servis benzeri katmanlar yalnız birkaç yerde: `api/_siparis.inc`, `cek/cek_lib.php`,
  `fatura/fatura_lib.php`, `cari/cari_olustur_lib.php`, `ayr.php`'deki `cari_bul`,
  `stok_miktar_bul`, `doviz_bul`, `m_p_*`. Web sipariş ekranları SQL'i satır içinde yazar.
- Muhasebe yoktur (ACCFICHE yalnız 0 yazmak için geçer).

## 2. Mimari

```
 ekranlar / API
      │   lumen_veri()->cari()->ara(...)        ← alan arayüzleri (CariDeposu, …)
      ▼
 ┌──────────────┐   ┌──────────────┐   (ileride) ┌──────────────┐
 │ Lumen sürücü │   │ LOGO sürücü  │ ··········· │ Netsis/Mikro │
 │ LM_ tabloları│   │ LG_/LV_ SQL  │             │   sürücü     │
 └──────────────┘   └──────────────┘             └──────────────┘
```

- **Mod seçimi:** `.env` → `LUMEN_VERI_KAYNAGI=logo` (varsayılan, bugünkü davranış) veya
  `lumen` (bağımsız). Uygulama çalışırken değişmez; bir kurulum bir moddadır.
- **Alan arayüzleri** (`includes/veri/`): her alan için bir PHP arayüzü
  (`PersonelDeposu`, `CariDeposu`, `StokDeposu`, `BelgeDeposu`, `FinansDeposu`,
  `CekDeposu`, `KurDeposu`). Dönen veri sürücüden bağımsız dizilerdir
  (`['id','kod','unvan','bakiye', …]`); LOGO kolon adları ekranlara sızmaz.
- **LOGO sürücüsü** bugünkü SQL'i taşır (davranış değişmez); **Lumen sürücüsü** `LM_`
  tablolarını kullanır.
- **Kimlikler:** her iki modda da tamsayı kimlik (LOGO'da LOGICALREF, Lumen'de ID).
  `M_` tabloları (görev, log, bildirim, yetki) iki modda ortaktır; kullanıcı kolonları
  bağımsız modda `LM_PERSONEL.ID`'ye bakar.
- **Geçiş dönemi koruması:** `includes/veri/moduller.php` hangi alanın bağımsız modda
  hazır olduğunu tutar. Hazır olmayan modüllerin sayfaları bağımsız modda "bu modül
  henüz yok" kartı gösterir ve menüde görünmez; böylece taşınmamış bir sayfa LOGO
  tablosu arayıp çökmez.

### Geçiş teknikleri (uygulamada)

| Teknik | Ne zaman | Örnek |
|---|---|---|
| **Ortak tablolar** `lumen_tablo('cari')` | Yeni/taşınan okuma sorguları; kolon adları iki modda aynı (LM_ adları) | `cari/cari.php`, `cari/lg_bakiye.php` |
| **LOGO uyumlu görünümler** `LMV_SLSMAN`, `LMV_CLCARD` | Ana veriyi LOGO kolon adlarıyla okuyan çok sayıda sorgu; bağımsız modda `$firma = 'dbo.LMV_'`, kişi için `{$GLOBALS['LUMEN_SLSMAN']}` | `loglar.php`, `ayar/ozel_cari_kisitlari.php` |
| **Depolar** (yazma) | Kayıt oluşturma/güncelleme; modlara göre dallanır | `lumen_personel_ekle()`, `cari_olustur_yeni()` |
| **Ortak alan kütüphanesi** | Aynı verinin birden çok kopyası (ekran/PDF/Excel) | `cari/cari_ekstre_lib.php` |
| **Mod dalı** | LOGO sınıflandırma kodlarına (TRCODE) dayanan özetler | `cari/lg_bakiyex.php` |

LOGO uyumlu görünümler yalnız **ana veri** içindir (kişi, cari; Faz 2'de stok kartı).
Hareket tabloları (fatura, kasa, çek) LOGO kodlarına göre sınıflandığından açıkça taşınır.
Modül koruması her zaman belirleyicidir: bir sayfa görünüm sayesinde "çalışıyor gibi"
olsa bile modülü bağımsız modda açılmadan kullanıcıya gösterilmez.

### Durum (2026-10-19, Faz 6 sonu)

| Faz | Durum |
|---|---|
| 0 — altyapı | ✅ veri katmanı, mod seçimi, çekirdek şema, modül koruması, `kur.sh --bagimsiz`, CI'da iki mod |
| 1 — kullanıcı + cari | ✅ giriş/beni hatırla, kullanıcı ve yetki yönetimi, görevler, loglar, bildirimler; cari liste/arama, yeni cari, bakiye, ekstre (ekran/PDF/Excel), özel cari kısıtı |
| 2 — stok | ✅ stok kartı/arama, birim ve koli ölçüsü, barkod, fiyat, depo miktarları (LOGO uyumlu `LMV_` görünümleri + INSTEAD OF tetikleyicileri) |
| 3 — sipariş | ✅ TL ve dövizli sipariş, satır işlemleri, yazdırma, API (`LMV_ORFICHE/ORFLINE`, üretilmiş: `scripts/gelistirme/logo_uyum_uret.py`) |
| 4 — belge | ✅ `belge/`: satış/alış faturası, irsaliye, iade, üretimden giriş, sayım; siparişten kısmi fatura; iptal; M31 |
| 5 — kasa/banka/çek | ✅ `finans/`: tahsilat, ödeme, virman, masraf, iptal, mükerrer gönderim koruması (M32); çek giriş/ciro/kendi çek/geri alma `LM_CEK` üzerinde (`cek/cek_lumen_lib.php`); cari ekstresi fişlere bağlanır |
| 6 — raporlar, kurulum, içe aktarma | ✅ rapor ekranları ve API rapor uçları `LMV_` görünümleri üzerinden; kurulum sihirbazında mod seçimi (bağımsızda veritabanı yoksa oluşturulur, yönetici LM_PERSONEL'e); `scripts/logo_aktar.php` |

### Kurulum ve LOGO'dan geçiş

**Yeni kurulum (bağımsız):** kurulum sihirbazında "Bağımsız" seçilir; boş (veya henüz
olmayan) bir veritabanı adı verilir. Sihirbaz veritabanını oluşturur, şemayı kurar
(`.env`: `LUMEN_VERI_KAYNAGI=lumen`) ve ilk yöneticiyi `LM_PERSONEL`'e yazar.
Uçtan uca test: `tests/kurulum/sihirbaz_bagimsiz.sh`.

**LOGO kullanan firmanın geçişi:**

```bash
# 1. Boş veritabanı aç (SQL Server), sonra deneme — hiçbir şey yazılmaz:
php scripts/logo_aktar.php --kaynak LOGODB --hedef LUMEN --firma 1 --donem 2 --sema --tarih 2027-01-01
# 2. Karşılaştırma tablosu "tamam" ise yaz:
php scripts/logo_aktar.php --kaynak LOGODB --hedef LUMEN --firma 1 --donem 2 --tarih 2027-01-01 --uygula
# 3. .env: AKL_DB_NAME=LUMEN, LUMEN_VERI_KAYNAGI=lumen
```

Ana veri (personel, cari, stok, birim, barkod, geçerli fiyat, depo) kimlikleriyle,
bakiyeler açılış olarak gelir: cari devir hareketi, ambar başına açılış sayımı,
kasa/banka açılış fişi, portföydeki çekler ve ödenmemiş kendi çeklerimiz. M_P_YETKI,
özel cari kısıtları ve kullanıcı ayarları kimlikler korunduğu için aynen taşınır;
kullanıcılar aynı kod ve parolayla girer. Araç tek işlemde yazar ve kaynak/hedef
toplamlarını (cari, stok, kasa, banka, çek) karşılaştırır; tutmazsa hiçbir şey yazmaz.
Test: `tests/aktarim/logo_aktar_test.php`.

Aktarılmayanlar: geçmiş belgeler (fatura, sipariş, kasa hareketleri; LOGO'da okunur
kalır), cariye özel fiyatlar, açık siparişler. Banka bakiyesi `BNFLINE` yoksa sıfır açılır.

### Bilinen sınırlar (bağımsız mod)

- **Üretim fişleri ekranı** (`stok/index.php`, LOGO STFICHE) kapalı: üretim girişi
  belge modülünden yapılır (Faturalar → Üretimden giriş; yalnız M15 yetkili depo
  personeli için menüde "Üretim ve Sayım").
- **LOGO nakit/fatura detay sayfaları** (`cari_belge`) kapalı; ekstre bağlantıları
  Lumen kasa fişine ve belgesine gider.
- Kasa/banka hesapları TL'dir; dövizli çekler LOGO'dan TL tutarıyla aktarılır.
- Fatura çıktısı bilgi/teslim nüshasıdır, e-Fatura/e-Arşiv değildir.
- Faturalama (BETA, `fatura/`) yalnız LOGO modundadır; e-Fatura/e-Arşiv ve resmi
  muhasebe sonraki aşamadır.

## 3. Lumen şeması (`sql/lumen_cekirdek/`)

Tek firma = tek veritabanı; dönem kilidi tarihle. Bakiyeler hareketlerden hesaplanır.

| Lumen | LOGO karşılığı | Not |
|---|---|---|
| LM_PERSONEL | LG_SLSMAN | kullanıcılar; parola/yetki M_P_YETKI'de kalır |
| LM_CARI | CLCARD | |
| LM_CARI_HAREKET + LMV_CARI_BAKIYE | CLFLINE + LV_GNTOTCL | borç/alacak TL, döviz tutarı ayrıca |
| LM_STOK, LM_BIRIM, LM_STOK_BIRIM, LM_BARKOD | ITEMS, UNITSETL, ITMUNITA, UNITBARCODE | birim çevrimi CARPAN |
| LM_FIYAT | PRCLIST | TUR 1 satış, 2 alış; tarihli |
| LM_DEPO | L_CAPIWHOUSE | |
| LM_BELGE + LM_BELGE_SATIR | ORFICHE/ORFLINE, STFICHE/STLINE, INVOICE | tek tablo; TUR sipariş/irsaliye/fatura/iade/üretim/sayım; KAYNAK_BELGE_ID zinciri |
| LM_STOK_HAREKET + LMV_STOK_MIKTAR | STLINE + LV_STINVTOT | ana birimde giriş/çıkış |
| LM_FINANS_HESAP/FIS/HAREKET | KSCARD/KSLINES, BANKACC/BNFLINE | kasa ve banka tek modelde |
| LM_CEK + LM_CEK_HAREKET | CSCARD, CSROLL, CSTRANS | geri alma = ters hareket |
| LM_KUR, LM_PARA_BIRIMI | L_DAILYEXCHANGES, L_CURRENCYLIST | |
| LM_SERI | (FICHENO üreticileri) | kilitli sayaç; MAX+1 yarışı yok |

## 4. Fazlar

| Faz | Kapsam | Bitti sayılır |
|---|---|---|
| **0** | Sürücü altyapısı, mod seçimi, modül kayıt defteri + koruma, bağımsız mod kurulumu (`demo/kur.sh --bagimsiz`), testlerin iki modda çalışması, CI | Bağımsız modda giriş sayfası açılır; LOGO modunda tüm testler eskisi gibi |
| **1** | Kullanıcı/giriş/yetki, Cari (liste, arama, kart, yeni cari, bakiye, ekstre) | Bağımsız modda giriş + cari ekranları uçtan uca; duman + akış testleri |
| **2** | Stok kartı, birim, barkod, fiyat, depo, stok miktarı | Stok ekranları iki modda |
| **3** | Satış/alış siparişi (TL + döviz), yazdırma | Sipariş akış testleri iki modda |
| **4** | İrsaliye ve **KDV'li fatura** (siparişten/serbest), iade; stok ve cari etkisi | Fatura → stok/cari hareketleri tutarlı |
| **5** | Kasa/banka: tahsilat, ödeme, virman; çek/senet portföyü | Finans akışları iki modda |
| **6** | Raporlar, API, kurulum sihirbazı (mod seçimi), LOGO'dan Lumen'e **içe aktarma** aracı | Bağımsız mod ürün olarak kurulabilir |
| sonra | Resmi muhasebe (TDHP, yevmiye, mizan, KDV), e-Fatura/e-Arşiv, diğer ERP sürücüleri | |

Her faz: önce Lumen şeması/sürücüsü, sonra ekranlar, sonra testler; LOGO modunda
davranış değişmez (mevcut duman + akış testleri her fazda yeşil kalır).
