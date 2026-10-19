# Değişiklik Günlüğü

## [Yayımlanmadı] — 1.3.0-beta.2 adayı

### Yeni

- **Sistem ve Yedek** (Ayarlar → Sistem Ayarları): çalışma modu (LOGO'ya bağlı /
  bağımsız) ve veritabanı görünür. **Uygulama içi yedekleme** — ek program
  gerekmez: tek tıkla yedek, her gün otomatik yedek (günün ilk kullanımında, arka
  planda), indirme, yedek dosyası yükleme, geri yükleme (öncesinde mevcut durumun
  yedeği otomatik alınır; tek işlem, hata olursa hiçbir şey değişmez). Bağımsız
  modda tüm veritabanı, LOGO modunda Lumen'in kendi tabloları yedeklenir. Yedek
  klasörü (ör. başka disk) ve saklama sayısı ayarlanabilir. Yalnız yönetici.
- **Dövizli fatura** (bağımsız mod): para birimi ve kur seçilir, fiyatlar dövizle
  girilir; stok, cari bakiye ve KDV TL'dir, döviz tutarı ve kur belgede, satırda ve
  cari harekette saklanır. Dövizli siparişten faturada kur, fatura gününün döviz alış
  kuru ile önerilir. Belge formunda "1.234,56" biçimli tutarlar doğru okunur.
- **Fatura çıktısı ve PDF** (bağımsız mod): firma ve cari bilgileri, logo, satırlar,
  KDV oranına göre döküm, toplamlar, yazıyla tutar, dövizde kur ve döviz toplamı,
  irsaliye/faturada imza alanları. Firma bilgileri: unvan, adres, vergi dairesi/no,
  telefon, e-posta. Çıktı e-Fatura/e-Arşiv değildir (belgede yazar).
- **Tanımlar** (bağımsız mod, M16): firma bilgileri; kasa/banka hesapları (IBAN
  denetimi; bakiyeli hesap pasifleştirilemez); depolar (stoklu depo ve merkez
  pasifleştirilemez); birimler (kullanılan silinemez); döviz kurları — elle ya da tek
  tıkla TCMB'den.
- **Excel'den aktar** (Tanımlar): cari ve stok kartları için şablon indir → yükle
  (.xlsx, .xls, .csv) → önizleme ve satır satır hata listesi → onay. İsteğe bağlı
  açılış bakiyesi, açılış stoku (merkez depo), satış/alış fiyatı, barkod, koli içi
  adet; var olan kodlar atlanır ya da kart bilgileri güncellenir. Tek işlemde yazılır.
- **Cari kartı ve stok kartı** (bağımsız mod): cari için bilgiler, borç/alacak/bakiye,
  belgeler, tahsilat/ödemeler, çekler ve hızlı işlemler (ekstre, fatura kes, tahsilat,
  ödeme — cari önceden seçili). Stok için eldeki/bekleyen/kullanılabilir miktar,
  birimler, barkodlar, fiyatlar, depolar ve belge bağlantılı hareketler (yürüyen
  bakiye; maliyet yalnız M31). Cari listesinden, bakiye listesinden, stok aramasından
  ve belge ekranından açılır.
- Bağımsız modda **depo personeli** (yalnız M15) üretimden giriş ve sayım girer;
  fatura göremez, fiyat görmez.

## [1.3.0-beta.1] — 2026-10-19 (ÖN SÜRÜM — Bağımsız Lumen BETA)

> ⚠️ **Ön sürümdür (beta).** Üretim için kararlı **v1.2.0**'ı kullanın. LOGO'ya bağlı
> kullanımda davranış v1.2.0 ile aynıdır (aşağıdaki düzeltmeler hariç); yeni olan
> **bağımsız mod** denemelik olarak sunulur.

Lumen artık LOGO olmadan kendi veritabanıyla ön muhasebe yapabilir; LOGO'ya bağlı
kullanım aynen sürer. Ayrıntı: `docs/mimari/bagimsiz-lumen.md`.

**Yükseltme (v1.2.0'dan):** `php scripts/sema_guncelle.php` çalıştırın (yeni yetki
kolonu M32 ve tablolar). Bağımsız moda geçiş için önce deneme kipinde
`scripts/logo_aktar.php` (ayrıntı belgede); canlı LOGO veritabanına yazmaz.

**Bilinen sınırlar (bağımsız mod):** üretim fişleri ekranı kapalı (üretim girişi
Faturalar ekranından, M31); dövizli fatura ve dövizli çekler TL tutarıyla;
Faturalama (BETA) yalnız LOGO modunda; e-Fatura/e-Arşiv ve resmi muhasebe yok;
geçiş aracı geçmiş belgeleri, cariye özel fiyatları ve açık siparişleri taşımaz.

### Yeni

- **İki çalışma modu** (`LUMEN_VERI_KAYNAGI=logo|lumen`): kurulum sihirbazında seçilir.
  Bağımsız modda veritabanı yoksa oluşturulur; ilk yönetici Lumen'de tanımlanır.
- **Faturalar ve belgeler** (bağımsız mod, M31): satış/alış faturası, irsaliye, iade,
  üretimden giriş, sayım; siparişten kısmi faturalama; iptal stok ve cariyi geri alır.
- **Kasa ve banka** (bağımsız mod, M32 — yeni yetki): tahsilat, ödeme, virman, masraf;
  çift gönderim koruması; iptal.
- Cari, stok, sipariş (TL/döviz), çek/senet, raporlar ve API iki modda çalışır.
- **LOGO'dan geçiş aracı** `scripts/logo_aktar.php`: ana veri kimlikleriyle, bakiyeler
  açılış olarak; deneme kipi ve kaynak/hedef toplam karşılaştırması.

### Düzeltmeler (LOGO modunu da etkiler)

- Cari ve stok aramalarında özel cari kısıtı eksikti (otomatik tamamlama, hızlı arama).
- Bazı ekranlar veritabanı hata metnini kullanıcıya gösteriyordu.
- Koli ölçüsünde ondalıklı ağırlık tamsayıya çevriliyordu.
- API fatura listesi satınalma iadelerini (TRCODE 6) satış faturası diye listeliyor,
  türleri yanlış adlandırıyordu (7 perakende, 8 toptan, 9 verilen hizmet).
- Cari bakiye detayında kullanılmayan 11 sorgu kaldırıldı.

## [1.2.0] — 2026-10-19

Bu sürüm, v1.0.0 üzerine kapsamlı bir güvenlik ve doğruluk denetiminin sonucudur ve
v1.1.0-beta.1 ön sürümünün (Faturalama BETA) yerini alır.
Yükseltme sonrası **mutlaka** `php scripts/sema_guncelle.php` çalıştırın.

### ⚠️ Para / veri hataları (önemli)

- **Çek girişi kuruşlu tutarları 100 katı kaydediyordu** (1.234,56 TL → 123.456 TL;
  CSCARD/CSROLL/CLFLINE ve cari bakiye). Bu sürümden önce girilmiş kuruşlu çekleri
  kontrol edin.
- **Döviz değiştir ekranı** döviz kodunu sipariş türüne (TRCODE) yazıyordu; sipariş
  listelerden kayboluyordu. Döviz artık TRCURR/TRRATE'e yazılır.
- **Yazdırılan / PDF siparişlerde KDV iki kez ekleniyordu** (genel toplam fazla).
- Eşzamanlı sipariş açılışında başka kullanıcının fişine yönlenme / API'de satırların
  başka fişe eklenmesi (MAX(LOGICALREF)).
- Çek geri alma başka dönemin kaydını güncel dönem tablolarında işleyebiliyordu.
- Satır düzenleme/silme sonrası iskonto yeniden dağıtılmıyordu.
- Dövizli siparişte döviz fiyatı/toplamı eski kurdan gösteriliyordu; toplu satır
  ekleme dövizli fişi TL yazıyordu.
- API fiyat listesi alış maliyetini satış fiyatı olarak döndürüyordu.
- Yazdırılan siparişte satır "Genel Toplam" iskontosuz tutara KDV ekliyordu
  (satırlar toplamı fişin net toplamını tutmuyordu); dövizli çıktılarda da aynı.
- "Dövizli Fiyat Listesi" çıktısı para birimini fişten değil oturumdan alıyordu
  ("₺ 0", "1 0 = 48 TL"); TL siparişte anlamsız tutar basıyordu.
- "Yeni cari oluştur ve aktar" başarılı işlemi hata olarak bildiriyordu (mükerrer cari).
- Sekmeler arası sızıntı: bir sekmede açılan sipariş, diğer sekmedeki ekleme
  işlemini kendine çekebiliyordu.

### 🔒 Güvenlik

- Kurulum sihirbazı elle kurulmuş sistemlerde oturumsuz erişime açıktı.
- Sistem ayarları ekranından sunucuda kod çalıştırılabiliyordu; ayarlar artık PHP
  dosyasına hiç yazılmaz (doğrulanmış JSON, `database/ayarlar.json`).
- M16 (Ayarlar) yetkili personel kendini yönetici yapabiliyordu.
- Veritabanı hatasında istek yönetici yetkisiyle çalışabiliyordu (`kontrol.php`).
- Zorla çıkış / parola değişikliği / pasifleştirme "beni hatırla" ve API
  belirteçlerini iptal etmiyordu; hatırlama belirteci artık her kullanımda yenilenir
  ve kopyalanan çerez tespit edilir.
- API: belirteçler özet olarak saklanır, kullanıcının güncel durumu her istekte
  denetlenir, hesap başına tek belirteç, saldırı modu API'ye de uygulanır, eksik
  rapor/sipariş yetkileri eklendi, fiyat değiştirme SP1 ister.
- Sipariş düzenleme uçlarında yetki ve özel cari kısıtı; yazdırma, rapor ve Excel
  çıktılarında özel cari kısıtı.
- XSS, Excel formül enjeksiyonu, açık yönlendirme, CSRF eksikleri, X-Forwarded-For
  ile IP sahteciliği, TLS doğrulaması kapalı dış çağrı.
- Web sunucusu kuralları (Apache/IIS/nginx) ve güvenlik başlıkları eşitlendi.

### ✨ Yeni

- **Faturalama (BETA, varsayılan kapalı)** — v1.1.0-beta.1'deki modül bu sürüme
  taşındı ve sertleştirildi: eşzamanlı istekte çift fatura / aynı numara kesilmez
  (uygulama kilidi), iptal edilmiş sipariş faturalanmaz, başka dönemin ya da
  LOGO'da muhasebeleştirilmiş/değiştirilmiş faturası geri alınmaz, özel cari kısıtı
  panelde ve geri almada da uygulanır, panel kesilecek KDV'siz tutarı gösterir
  (betada KDV dahil sipariş toplamı görünüyordu). **KDV'siz çalışır** — README →
  Faturalama (BETA).
- **Bildirim kutusu** (ana sayfa zili): görev olayları, vadesi yaklaşan görev ve
  çekler, güvenlik ve ayar olayları.
- **Entegrasyon hesabı** (YETKI=3): yalnız API, yalnız okuma.
- **Türkçe duyarsız arama**: tüm ekranlarda "TABAGI" = "Tabağı", "IC ANADOLU" = "İç Anadolu".
- Sipariş satırında çakışma koruması; çek işlemlerinde mükerrer gönderim koruması.
- Zamanlanmış görevler: `scripts/bos_taslak_temizle.php`, `scripts/log_hijyeni.php`,
  `scripts/bildirim_hatirlatici.php`, `scripts/sema_guncelle.php`.
- Parola en az 8 karakter; ODBC Driver 18 desteği (`AKL_DB_TRUST_CERT`,
  `AKL_DB_ENCRYPT`); `TRUSTED_PROXIES`, `PAZARLAMACI_CARI_ONEKI` ayarları.

### 🎨 Görünüm

- Parametresiz/kaydı olmayan sayfalar düz metin veya boş beyaz sayfa yerine ortak
  hata kartı gösterir (`lumen_hata_sayfasi`); veritabanı hata ayrıntısı artık
  ekrana basılmaz.
- Sipariş ekranları ve rapor/ayar sayfalarındaki birincil düğmeler ortak bordo
  palete çekildi; bildirimler sayfası ve zil rozeti ortak tasarıma uyarlandı;
  pazarlamacı performans kartları ve üretim ekranı mobilde düzeltildi.

### 🧪 Geliştirme

- **Demo ortamı** (`bash demo/kur.sh`): SQL Server + sentetik LOGO verisi + uygulama.
- Birim, duman (tüm sayfalar × 3 kullanıcı) ve akış (uçtan uca iş akışları) testleri;
  GitHub Actions'ta her push'ta.
- Temiz kurulumda çalışmayan şema betikleri düzeltildi (giriş denemesi koruması
  tablosu hiç oluşmuyordu; 11 tablonun betiği yoktu).

### Yükseltme notları (v1.0.0 → v1.2.0)

Bu yol demo ortamında uçtan uca denendi: v1.0.0'ın kendi şema betikleriyle
kurulmuş veritabanı + `sema_guncelle.php` → tüm sayfa ve akış testleri temiz.

1. **Yedek alın** (veritabanı + uygulama klasörü).
2. Dosyaları güncelleyin, `composer install`.
   **Kendi `_bilgi_.inc` dosyanızı koruyun** (üzerine yazmayın): v1.0.0'da Ayarlar
   ekranı değerleri bu dosyaya yazıyordu. Yeni sürüm eski dosyayla çalışır; ekrandan
   yapılan yeni değişiklikler artık `database/ayarlar.json`'a kaydedilir ve dosyadaki
   değerlerin üzerine uygulanır. `database/` klasörüne web sunucusu kullanıcısının
   yazma izni olmalı (README → Yazma izinleri).
3. `php scripts/sema_guncelle.php` (tekrar çalıştırılabilir; eksik tabloları ve
   kolonları ekler, mevcut veriye dokunmaz).
4. `.env.example`'daki yeni ayarlara bakın (`AKL_DB_TRUST_CERT`, `AKL_DB_ENCRYPT`,
   `TRUSTED_PROXIES`, `PAZARLAMACI_CARI_ONEKI`).
5. Günlük zamanlanmış görevleri kurun (README → Zamanlanmış Görevler).
6. Parolası 8 karakterden kısa kullanıcılar giriş yapabilir; parola değiştirirken
   yeni kural uygulanır. Mevcut API belirteçleri süreleri dolana dek (30 gün)
   çalışmaya devam eder; yeni belirteçler özet olarak saklanır.
7. Bu sürümden önce girilmiş **kuruşlu çekleri** kontrol edin (yukarıdaki ×100 hatası).

### v1.1.0-beta.1 (Faturalama BETA) kullananlar

v1.2.0 betanın yerini alır; Faturalama modülü bu sürümde de BETA ve varsayılan
kapalıdır. Kendi `_bilgi_.inc`'inizi koruyun (faturalama ayarlarınız oradadır) ve
`php scripts/sema_guncelle.php` çalıştırın. Mevcut fatura kayıtları (`M_FATURA_LOG`)
olduğu gibi geri alınabilir kalır.

### Bilinen sınırlar

- Yeni sipariş satırı fiyatı, fiyat değiştirme yetkisi (SP1) olmayan kullanıcı için
  sunucuda liste fiyatına karşı doğrulanmaz (arayüz alanı kilitler).
- Sipariş yetkisi (M3) olan personel, görebildiği her siparişi düzenleyebilir
  (siparişin sahibine göre ayrım yok).
- Çıkış bağlantısı (`?cikis=1`) GET isteğidir.

## [1.0.0] — 2026-07-25

İlk kararlı sürüm.
