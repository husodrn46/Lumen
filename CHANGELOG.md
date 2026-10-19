# Değişiklik Günlüğü

## [Yayımlanmadı] — v1.2.0 adayı

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
