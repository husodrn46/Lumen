# Değişiklik Günlüğü

## [Yayımlanmadı] — v1.2.0 adayı

Bu sürüm, v1.0.0 üzerine kapsamlı bir güvenlik ve doğruluk denetiminin sonucudur.
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

- **Bildirim kutusu** (ana sayfa zili): görev olayları, vadesi yaklaşan görev ve
  çekler, güvenlik ve ayar olayları.
- **Entegrasyon hesabı** (YETKI=3): yalnız API, yalnız okuma.
- **Türkçe duyarsız arama**: tüm ekranlarda "TABAGI" = "Tabağı", "IC ANADOLU" = "İç Anadolu".
- Sipariş satırında çakışma koruması; çek işlemlerinde mükerrer gönderim koruması.
- Zamanlanmış görevler: `scripts/bos_taslak_temizle.php`, `scripts/log_hijyeni.php`,
  `scripts/bildirim_hatirlatici.php`, `scripts/sema_guncelle.php`.
- Parola en az 8 karakter; ODBC Driver 18 desteği (`AKL_DB_TRUST_CERT`,
  `AKL_DB_ENCRYPT`); `TRUSTED_PROXIES`, `PAZARLAMACI_CARI_ONEKI` ayarları.

### 🧪 Geliştirme

- **Demo ortamı** (`bash demo/kur.sh`): SQL Server + sentetik LOGO verisi + uygulama.
- Birim, duman (tüm sayfalar × 3 kullanıcı) ve akış (uçtan uca iş akışları) testleri;
  GitHub Actions'ta her push'ta.
- Temiz kurulumda çalışmayan şema betikleri düzeltildi (giriş denemesi koruması
  tablosu hiç oluşmuyordu; 11 tablonun betiği yoktu).

### Yükseltme notları

1. Dosyaları güncelleyin, `composer install`.
2. `php scripts/sema_guncelle.php`
3. `.env.example`'daki yeni ayarlara bakın (`AKL_DB_TRUST_CERT`, `TRUSTED_PROXIES`,
   `PAZARLAMACI_CARI_ONEKI`).
4. Günlük zamanlanmış görevleri kurun (README → Zamanlanmış Görevler).
5. Parolası 8 karakterden kısa kullanıcılar giriş yapabilir; parola değiştirirken
   yeni kural uygulanır.

## [1.0.0] — 2026-07-25

İlk kararlı sürüm.
