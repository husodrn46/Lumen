# Değişiklik Günlüğü

## [1.2.4] — 2026-10-22

Güvenlik bakım sürümü (v1.3.0 sürüm adayının bağımsız kabul testinde bulundu). Şema değişikliği
yoktur; v1.2.3'ün üzerine dosyaları kopyalamanız yeterlidir.

### 🔒 Güvenlik

- **Cari finans bilgisi:** "Müşteri Bakiye" ya da "Cari Bakiye/Ekstre" yetkisi olmayan bir
  kullanıcı (ör. depo personeli) adresi doğrudan yazarak her carinin ekstresini, ekstre
  PDF/Excel'ini, nakit ve fatura görünümlerini açabiliyordu. Artık bu ekranlar M4/M20 ya da
  CR1/CR2 yetkisi ister (yönetici her zaman görür).
- **Önceki dönem fatura/hizmet görünümleri** özel cari kısıtını uygulamıyordu; artık uygular ve
  faturanın gerçek carisini denetler.
- **Sipariş ekranı:** fiyat görme yetkisi olmayan kullanıcı satır fiyatlarını göremiyor ama
  özet tutarları (ara toplam, KDV, genel toplam) görüyordu; artık onlar da gizlenir.

## [1.2.3] — 2026-10-22

Hata düzeltme sürümü (v1.3.0 betalarında büyük veriyle yapılan performans testinde bulunanlar).
Şema değişikliği yoktur; v1.2.2'nin üzerine dosyaları kopyalamanız yeterlidir.

### 🐛 Düzeltmeler

- **Takılan sorgu:** web isteğinde tek bir veritabanı sorgusu en fazla 100 sn sürer
  (`.env`'de `LUMEN_SORGU_SINIRI` ile değişir). Önceden çok uzun süren bir rapor, kullanıcının
  oturumunu dakikalarca kilitleyebiliyordu.
- **Cari ekstresi PDF:** binlerce hareketli caride bellek yetmediği için açılmıyordu. Artık son
  500 hareketi içerir, öncekiler "Devir" satırında toplanır (bakiye değişmez; tamamı Excel'de).
- **Cari ekstresi Excel:** binlerce hareketli caride 30 sn sınırına takılıp açılmıyordu (artık ~1 sn).
- **Ürün ligi Excel'i:** 20.000 üründe 23 sn → 2,5 sn. Büyük Excel/PDF çıktılarında bellek
  sınırı gerektiğinde 256 MB'a çıkarılır.
- **En İyi 20 Ürün (son 30 gün):** veritabanı sunucusunun saat dilimi farklıysa gece yarısından
  sonra bugünün satışları görünmüyordu.
- **Ekstre dönem seçimi:** yıllar sabit yazılmıştı ("2026 (Aktif)", "2025"); artık
  "Aktif Dönem / Önceki Dönem / Tüm Dönemler".
- **Kullanıcı formu:** parola ipucu "en az 4 karakter" diyordu; kural 8 karakterdir.

## [1.2.2] — 2026-10-21

Güvenlik bakım sürümü. Uygulama davranışı ve şema v1.2.1 ile aynıdır.

### 🔒 Güvenlik

v1.3.0 öncesi güvenlik denetiminde, kararlı sürümü de etkileyen şu açıklar bulundu ve kapatıldı:

- **Özel cari kısıtı:** çek giriş, ciro ve kendi çekimiz uçları; sipariş/fatura yazdırma
  görünümü (`yazdir/fis_goruntule.php`) ve API çek listesi, kullanıcının göremediği cariyi
  süzmüyordu.
- **Sipariş satırı:** satır ekleme uçları, var olmayan ya da sipariş olmayan bir kimliğe
  satır yazabiliyordu; sevk edilmiş satırın miktarı sevk edilenin altına düşürülebiliyordu.
- **Kurulum sihirbazı:** sunucu ve veritabanı adı denetlenir (bağlantı dizesine ek
  parametre eklenemez).
- **Sunucu kuralları:** `includes/` klasörü, `web.config` ve `.lumen.gz` dosyaları
  tarayıcıdan açılmaz (`.htaccess`, `web.config`, README nginx kuralları).

## [1.2.1] — 2026-10-21

Güvenlik bakım sürümü. Uygulama davranışı v1.2.0 ile aynıdır; şema değişikliği yoktur.

### 🔒 Güvenlik

- **PHP2Shell (CVE-2026-109618, CVE-2026-109619)** — PHP'nin kendisinde kimlik
  doğrulamasız uzaktan kod çalıştırma açıkları (Lumen kodunda değil). Sunucunuzda en az
  **PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12** olmalı; tam çözüm yalnız PHP güncellemesidir.
  Güncelleyene kadar `php.ini`'de `session.upload_progress.enabled=0` yapıp web sunucusunu
  yeniden başlatın (Lumen bu özelliği kullanmaz).
- Lumen yamasız PHP'yi **kurulum sihirbazında** ve **yönetici ana sayfasında** uyarır.
- `web.config` (IIS): istek gövdesi 50 MB → 32 MB; URL, sorgu dizesi ve başlık sınırları.
  `.htaccess` (Apache): `LimitRequestBody` 32 MB; mod_php'de yükleme ilerlemesi kapalı.
- Demo imajı yamasız PHP ile derlenmez; `kur.sh` taban imajı her kurulumda yeniler.

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
