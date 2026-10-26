# Ekonomik Göstergeler — EVDS 3

Bu modül kullanıcıya ait EVDS API anahtarıyla ekonomik zaman serilerini **yalnız okur**.
LOGO ve bağımsız Lumen için aynıdır; kur, fatura, sipariş veya muhasebe kaydı yazmaz.
MCP tabanlı sohbet ve model bağlantısı bu ilk adımın kapsamında değildir.

## Resmî sözleşme

26 Ekim 2026 tarihinde incelenen [EVDS web servis kılavuzu](https://evds3.tcmb.gov.tr/igmevdsms-dis/documents/showDocument?docId=8):

- Ana uç `https://evds3.tcmb.gov.tr/igmevdsms-dis/`; anahtar yalnız `key` HTTP başlığında.
- `serieList/type=json&code=...` ile seri adı ve sıklık doğrulanır.
- `series=...&startDate=gg-aa-yyyy&endDate=gg-aa-yyyy&type=json` ile veri alınır.
- Yanıt en fazla 1.000 gözlemdir. Modül, günlük seriler de eksiksiz kalabilsin diye
  tarih aralığını en fazla 1.000 takvim günüyle sınırlar. Dönüşüm/formül uygulanmaz.
- Aynı sorgu kişi bazında gün boyunca önbellekten okunur. Başarısız sorguda bir dakika
  beklenir. Lumen günde kişi başına en fazla 20 farklı sorgu dener; bu bir uygulama
  sınırıdır, TCMB kotası değildir. Eski önbellek yeni veri alınamazsa tarihi ve uyarısıyla sunulur.

[EVDS-MCP duyurusu](https://www.tcmb.gov.tr/wps/wcm/connect/TR/TCMB+TR/Main+Menu/Duyurular/Basin/2026/DUY2026-46)
ve [MCP kılavuzu](https://evds3.tcmb.gov.tr/igmevdsms-dis/documents/showDocument?docId=27)
ayrı bir protokol tanımlar: OAuth ve ayrıca bir yapay zekâ modeli gerekir. Web servisi
paneli MCP bağlantısı kurulmuş gibi gösterilmez.

## Kurulum ve kişisel anahtar

1. `php scripts/sema_guncelle.php` çalıştırılır. M34 yetkisi personele verilir;
   yönetici otomatik erişir.
2. Kullanıcı EVDS hesabındaki **Benim Sayfam → Profilim → API KEY KOPYALA** üzerinden
   kendi anahtarını alır. Anahtar sohbet, URL veya depoya yazılmaz.
3. Lumen bağlantı kaydı firma/kullanıcıya özeldir. AES-256-GCM şifrelemesi aynı
   bağlamla doğrulanır. Anahtar forma tekrar basılmaz, ortak ayarlarla paylaşılmaz.
4. İlk kayıt `database/evds-master.key` dosyasını üretir; `database/` web erişimine
   kapalı ve git dışında olmalıdır. PHP için OpenSSL ve cURL gerekir.
   İstenirse dosya konumu `LUMEN_EVDS_ANAHTAR_DOSYASI` ortam değişkeniyle belirlenir.
5. **Bu dosyayı güvenli ve ayrı yedekleyin.** SQL yedeği şifreli anahtarları içerir,
   ana anahtarı içermez. Dosya kaybolursa kullanıcılar API anahtarlarını yeniden girer.
   Anahtar değiştirme/kaldırma yalnız kişinin bağlantısını ve önbelleğini temizler.

## Veri ve doğrulama

Ana sayfadaki **Ekonomik Göstergeler** veya yetkiniz varsa Raporlar bağlantısından
modülü açın. **Kişisel bağlantı** bölümüne kendi EVDS anahtarınızı girin. Kaydetme
anahtarın geçerliliğini doğrulamaz; **Veriyi getir** erişimi sınar. GET ile sayfa açmak
veya gözlem sayfası değiştirmek dış istek göndermez.

Hazır USD döviz satış ve EUR döviz alış serileri güncel kılavuzdaki kodlardır.
**Diğer EVDS serisi** seçeneğinde, enflasyon/faiz gibi bir serinin kodunu resmî EVDS
**Tüm Seriler** sayfasından alın. Kod, seri adı ve sıklığı sorgu sırasında doğrulanır.
En fazla 1.000 günlük bir aralık seçilir. **CSV indir**, gösterilen sonucun tamamını
kaynak/dönem bilgisiyle verir; ekrana 25'er satır gelir. **Sentetik örneği göster**
anahtar ve ağ bağlantısı gerektirmez; hem ekranda hem CSV'de gerçek veri olmadığı belirtilir.

Seri adı/sıklığı EVDS kataloğundan gelir. Özel seri kodu katalogda doğrulanmadan
gözlem isteği yapılmaz. Eksik değerler `null` kalır; sıfır veya tahmini değerle doldurulmaz.
Günlük, aylık, yıllık ve `YYYY-Qn` dönemleri desteklenir; diğer biçimler açık hata verir.
Kaynak, gözlem dönemi ve alınma zamanı sonuçta korunur. Değişim ilk ve son **dolu**
gözlem arasındadır; otomatik olarak yıllık enflasyon veya resmî muhasebe düzeltmesi sayılmaz.

[sentetik.json](sentetik.json) TCMB verisi değildir; yalnız örnek ve test içindir.
Gerçek API anahtarı kullanılmadığından canlı servis uçtan uca kabulü yapılmamıştır.
Testler belge sözleşmesini sentetik yanıtlarla doğrular; kullanıcı hesabındaki erişim
ve güncel seri yayımı gerçek anahtarla ilk kullanımda ayrıca doğrulanır.

Eski `rapor/api/tcmb_enflasyon_guncelle.php` ayrı bir akıştır; bu geliştirmede eski
SQLite ayarı otomatik alınmaz. Oradaki eski evds2/URL anahtarı kullanımı ayrı bir
bakım konusu olarak kalır; yeni modülde kullanılmaz.
