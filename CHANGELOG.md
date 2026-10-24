# Değişiklik Günlüğü

## [Yayımlanmadı]

### 🐛 Düzeltmeler

- Günlük İşlemler ekranı ve Excel çıktısı, satış ve kasa kayıtlarında yalnız tarih
  alanına düşmek yerine kaynak işlem saatini gösterir; saat kaynağı yoksa `--:--` kullanır.
- Yeni cari ve cari düzenleme akışları mükerrer vergi no/TCKN eşleşmesini cari kodu ve
  unvanıyla bildirir; bilinçli istisnalar onaylanabilir. Excel aktarımı mükerrer satırı reddeder.

## [1.3.1] — 2026-10-24 (GÜVENLİK VE BAKIM)

### 🔒 Güvenlik

- Yetki denetiminin dahili HTTP isteği artık istek sahibinin `Host` başlığına güvenmez;
  yalnızca yapılandırılmış loopback hedefe gider ve TLS sertifikasını doğrular.
- Özel cari kısıtı döviz sipariş listesi/yazdırma/Excel ve siparişe ürün
  ekleme-düzenleme-yardımcı uçlarında da uygulanır. Doğrudan kimlik yazarak gizli
  siparişe erişim regresyon testleriyle engellenir.
- SQL Server sertifika kimliği doğrulaması güvenli varsayılandır. Kendinden imzalı
  yerel sunucu için `AKL_DB_TRUST_CERT=1` bilinçli olarak seçilmelidir.
- Veritabanı ve uygulama istisnaları kullanıcıya ham sorgu, dosya yolu ya da hata
  ayrıntısı döndürmez; ayrıntı sunucu günlüğüne referans koduyla yazılır.
- CSP'ye `object-src`, `base-uri`, `frame-ancestors` ve `form-action` sınırları eklendi.
  Satır içi kodu kaldırma planı `docs/guvenlik/CSP_GECIS.md` belgesindedir.
- Demo nginx imajı sabit digest kullanır; Trivy imaj ve Gitleaks sır taramaları ile
  Composer, GitHub Actions ve Docker için Dependabot güncellemeleri eklendi.

### 🎨 Arayüz ve bakım

- Ekranlar ortak bordo tasarım diline ve daha tutarlı mobil yerleşime uyarlandı.
- Müşteri yanında gizli mod düğmesi ortak başlığı olan sayfalarda başlığa taşındı;
  başlıksız sayfalarda içerikle çakışmaması için alt boşluk bırakıldı.
- Pull request şablonu ve katkı rehberi; dal, test, güvenlik ve sürüm akışını belgeledi.

**Yükseltme (v1.3.0 → v1.3.1):** dosyaları güncelleyip `composer install` çalıştırın.
Bu sürümde şema değişikliği yoktur. Yetki denetimi farklı bir yerel adrese gidiyorsa
`LUMEN_DENETIM_BASE_URL` değerini açıkça verin. Kendinden imzalı SQL Server sertifikası
kullanan kurulumlar, riski kabul ederek `AKL_DB_TRUST_CERT=1` ayarlamalıdır.

## [1.3.0] — 2026-10-22 (KARARLI — Bağımsız Lumen)

**Bağımsız Lumen kararlı sürümü.** Lumen LOGO Tiger olmadan, kendi veritabanıyla ön muhasebe
programı olarak çalışır; LOGO'ya bağlı kullanım aynen devam eder (`.env` `LUMEN_VERI_KAYNAGI`,
varsayılan `logo`).

- **Bağımsız modda:** cari ve ürün kartları (ekleme/düzenleme), satış ve alış siparişi,
  irsaliye ve fatura (dövizli dahil; siparişten/irsaliyeden kısmi), kasa/banka, çek, vade takibi,
  toplu fiyat, raporlar, yedekleme ve geri yükleme, Excel'den aktarma, dönem kilidi, LOGO'dan
  geçiş aracı (`scripts/logo_aktar.php`).
- **Her iki modda:** v1.2.x'e göre güvenlik ve performans düzeltmeleri (ayrıntı: aşağıdaki
  sürüm adayları ve betalar; v1.2.3 ve v1.2.4 kararlı düzeltmeleri dahil).
- **Kabul:** sürüm adayları, kodu GitHub'dan çeken bağımsız bir yapay zekâ ajanıyla
  `docs/test/KABUL_TESTI.md`'ye göre denendi (rc.1 koşullu → düzeltildi; rc.2 kabul).

**Yükseltme (v1.2.x → v1.3.0, LOGO modu):** dosyaları güncelleyip `composer install`, sonra bir
kez `php scripts/sema_guncelle.php`. LOGO tablolarına dokunulmaz; davranış değişmez.
Bağımsız moda geçiş: `docs/mimari/bagimsiz-lumen.md` → "Kurulum ve LOGO'dan geçiş".
Kullanım: `docs/kullanim/KILAVUZ.md`. Bilinen sınırlar: `docs/mimari/bagimsiz-lumen.md`.

**Bilinen küçük konular** (sonraki bakım sürümü): belgede para birimi gidiş-dönüşünde fiyat
kuruş kayması; sipariş ekranında kısmi teslim miktarı gösterilmiyor.

## [1.3.0-rc.3] — 2026-10-22 (SÜRÜM ADAYI)

> ⚠️ **Sürüm adayıdır.** Üretimde LOGO'ya bağlı kullanım için kararlı **v1.2.4**'ü kullanın.

rc.2'nin yeniden kabul denemesinde (yapay zekâ ajanı) karar **KABUL**; bildirilen küçük konular:

### ✨ Yeni
- **Cari kartı düzenleme** (bağımsız mod, cari kartında "Düzenle"): unvan, tür, vergi bilgileri,
  adres, iletişim, vade günü, risk limiti, özel kod, pazarlamacı, aktiflik. Önceden yalnız Excel'le.

### 🔒 Güvenlik
- Yazdırma tasarım ekranı sunucu dosya yolunu ve veritabanı hata metnini her kullanıcıya
  gösteriyordu (`?debug=1` ile ayrıntı da); artık yalnız yöneticiye.

### 🐛 Düzeltmeler
- Sipariş ekranında koli barkodu okutulunca satır "Koli" kipinde açılır.
- Sipariş ekranında var olmayan "Resimli/Resimsiz Teklif" bağlantıları gizlendi (dosya varsa görünür).
- Ekstre ekranı ve PDF'inde Türkçe harfler ("İŞLEM TÜRÜ", "TOPLAM GİRİŞ", "Borçlu"); bazı başlıklar.
- Vade takibi telefonda daha sığar; Yeni Cari ekranı bağımsız modda doğru yönlendirir.

## [1.3.0-rc.2] — 2026-10-22 (SÜRÜM ADAYI)

> ⚠️ **Sürüm adayıdır.** Üretimde LOGO'ya bağlı kullanım için kararlı **v1.2.4**'ü kullanın.
> v1.2.4'teki güvenlik düzeltmesini içerir.

rc.1'in bağımsız kabul testinde (yapay zekâ ajanı, kodu GitHub'dan çekerek; `docs/test/KABUL_TESTI.md`)
bulunanlar. Otomatik testler, GitHub iş akışı ve performans ölçümü rc.1'de de geçmişti; elle
denemede çıkan sorunlar:

### 🔒 Güvenlik
- **Cari finans bilgisi** (LOGO modunu da etkiler): bakiye/ekstre yetkisi olmayan kullanıcı
  (ör. depo personeli) adresi doğrudan yazarak cari kartını, ekstreyi ve ekstre PDF/Excel'ini
  açabiliyordu. Artık "Müşteri Bakiye" ya da "Cari Bakiye/Ekstre" yetkisi gerekir. Önceki
  dönem fatura/hizmet görünümlerinde özel cari kısıtı da uygulanır. Sipariş ekranında fiyat
  görme yetkisi olmayana özet tutarlar da gizlenir.

### 🐛 Düzeltmeler
- **Siparişten KDV'siz fatura:** stok aramadan çoklu eklenen sipariş satırları KDV %0
  yazılıyordu ve "Faturala" bunu sessizce faturaya taşıyordu. Bağımsız modda satır ürünün KDV'siyle
  yazılır; "Faturala" ekranında KDV seçilebilir, %0 kalmış satıra ürün KDV'si önerilir ve uyarılır.
- **Koli barkodu:** koli barkoduyla eklenen satır 1 ADET geliyordu; birim KOLİ'ye çevrilince fiyat
  değişmiyordu (bir koli bir adet fiyatına satılabiliyordu). Barkodun birimi seçili gelir, fiyat
  çarpanla hesaplanır ve birim değişince çevrilir. Yeni ürün formunda iki boş barkod satırı.
- **Vergi no / TCKN:** firma bilgilerinde ve Excel'den cari aktarımında kontrol basamağı denetlenir.
- **Yedek ekranı:** sayfa yenilenince (formu yeniden gönder) yedek alma ya da geri yükleme
  tekrarlanıyordu; artık her form bir kez işlenir.
- Fatura PDF'inde başlıklar Türkçe büyük harfle ("MİKTAR"); ekstre PDF'inde Türkçe harfler;
  Günlük İşlemler'de dövizli faturanın kuru; vade takibi telefonda sığar ve Türkçe harf duyarsız
  arar; giriş sayfasında tarayıcı konsol hatası; bazı ekran başlıklarında Türkçe harfler; ikon yolu.

## [1.3.0-rc.1] — 2026-10-22 (SÜRÜM ADAYI)

> ⚠️ **Sürüm adayıdır.** Kabul testi tamamlanınca v1.3.0 olarak yayımlanacak. Üretimde
> LOGO'ya bağlı kullanım için kararlı **v1.2.3**'ü kullanın.

**v1.3.0 = Bağımsız Lumen:** LOGO olmadan, kendi veritabanıyla ön muhasebe — cari ve ürün
kartları, satış/alış siparişi, irsaliye ve fatura (dövizli dahil), kasa/banka, çek, vade
takibi, toplu fiyat, raporlar, yedekleme, Excel'den aktarma ve LOGO'dan geçiş aracı.
LOGO'ya bağlı kullanım aynen devam eder (`.env` `LUMEN_VERI_KAYNAGI`, varsayılan `logo`).

- beta.1–beta.7'deki her şey (aşağıda) ve v1.2.3'e kadarki kararlı düzeltmeler.
- Vade takibinde carinin vade günü yalnız faturalara uygulanır (avans/fazla tahsilat kendi
  tarihiyle görünür). Kabul testi keşif listesine yeni ekranlar eklendi.

**Yükseltme (v1.2.x → v1.3.0, LOGO modu):** dosyaları güncelleyip `composer install`, sonra
bir kez `php scripts/sema_guncelle.php`. LOGO tablolarına dokunulmaz; davranış değişmez.
Bağımsız moda geçiş: `docs/mimari/bagimsiz-lumen.md` → "Kurulum ve LOGO'dan geçiş".
Kullanım: `docs/kullanim/KILAVUZ.md`. Bilinen sınırlar: `docs/mimari/bagimsiz-lumen.md`.

## [1.3.0-beta.7] — 2026-10-22 (ÖN SÜRÜM — Bağımsız Lumen BETA)

> ⚠️ **Ön sürümdür (beta).** Üretim için kararlı **v1.2.2**'yi kullanın.

### ✨ Yeni

- **Toplu fiyat güncelleme** (bağımsız mod, Tanımlar → Toplu fiyat): ürün grubu ve kod
  başlangıcıyla seçilen ürünlerin satış ya da alış fiyatı yüzde ya da tutar kadar değişir,
  isteğe bağlı yuvarlanır. Önce önizleme, sonra onay; eski fiyat silinmez, seçilen tarihten
  geçerli yeni fiyat yazılır. 20.000 ürün 1,5 sn.
- **Alış siparişi** (bağımsız mod): Faturalar → Yeni Belge → "Alış Siparişi". Stok ve cariyi
  etkilemez; "Faturala" ile kısmi ya da tam alış irsaliyesi/faturasına aktarılır, irsaliyesi
  sonra faturalanır. Bekleyen miktar stok kartında görünür; bağlı belgesi varken iptal edilemez.
- **Vade takibi** (bağımsız mod, Raporlar → Vade Takibi): tahsil edilecek ve ödenecek açık
  kalemler vadesine göre — vadesi geçmiş, bu hafta, bu ay, sonrası; tahsilat/iadeler en erken
  vadeli kalemden düşülür. Cari kartında "Vadesi geçmiş" tutarı. Vadesi girilmeyen faturaya
  carinin vade günü uygulanır.

### 🐛 Düzeltmeler

- Büyük Excel çıktıları (ör. 6.000 hareketli carinin ekstresi) zaman zaman bellek sınırına
  takılıyordu; Excel/PDF çıktılarında bellek sınırı en az 256 MB'a çıkarılır. Performans
  ölçümü artık üç denemenin herhangi biri hatalıysa hata sayar (önceden yalnız sonuncusuna bakıyordu).

## [1.3.0-beta.6] — 2026-10-22 (ÖN SÜRÜM — Bağımsız Lumen BETA)

> ⚠️ **Ön sürümdür (beta).** Üretim için kararlı **v1.2.2**'yi kullanın.

### ✨ Yeni

- **Ürün kartı ekranı** (bağımsız mod): ana sayfadaki "Yeni Ürün" ve stok kartındaki
  "Düzenle" ile tek ürün eklenir/düzenlenir — ad, grup, KDV, birimler ve çarpanlar, barkodlar,
  satış ve alış fiyatı. Önceden ürün yalnız Excel'den aktarılabiliyordu. Kod ve ana birim
  sonradan değişmez; fiyat değişikliği bugünden geçerli yeni fiyat olarak saklanır; belgede
  kullanılmış birim kaldırılamaz. Yetki: "Yeni Stok" (M11) ya da yönetici; alış fiyatı
  faturalama yetkisi ister.
- **İrsaliyeden fatura** (bağımsız mod): irsaliye ekranındaki "Faturala" ile kısmi ya da tam
  fatura kesilir. Stok yalnız irsaliyede düşer, fatura cariyi borçlandırır; faturalanan miktar
  irsaliye satırında izlenir, fatura iptal edilince geri alınır. LOGO uyumlu görünümlerde
  LOGO'daki gibi görünür: irsaliye satırı faturayı gösterir, faturanın ayrı stok fişi/satırı
  yoktur (raporlarda çift sayım olmaz).

### 🐛 Düzeltmeler

- LOGO uyumlu stok satırı görünümü, iptal edilmiş faturaya bağlı irsaliye satırını "faturalı"
  gösteriyordu.
- Siparişten belgede satır birimi kaynak satırın birimiyle aynı olmak zorunda (farklı birimle
  kalan miktar denetimi aşılabiliyordu).
- **Cari ekstresi dönem seçimi:** yıllar ekrana sabit yazılmıştı ("2026 (Aktif)", "2025");
  artık "Aktif Dönem / Önceki Dönem / Tüm Dönemler". Bağımsız modda seçim kutusu gösterilmez
  (ekstre her zaman bütün geçmişi verir) ve PDF/Excel başlığı "Tüm Hareketler" der.

## [1.3.0-beta.5] — 2026-10-22 (ÖN SÜRÜM — Bağımsız Lumen BETA)

> ⚠️ **Ön sürümdür (beta).** Üretim için kararlı **v1.2.2**'yi kullanın.

### ⚡ Büyük veriyle performans

Bağımsız mod 5.000 cari, 20.000 ürün ve bir yıllık hareketle (18.000 belge, 78.000 belge
satırı, 15.000 kasa fişi) ölçüldü: `tests/performans/olc.php --veri`. Ölçülen 32 ekran ve
işlemin hepsi sınır içinde (ekranlar 1 sn'nin altında).

- **Raporlar:** satış raporlarında irsaliye/fatura eşleşmesi her satır için bütün tabloyu
  tarıyordu (bir rapor 3 dakikayı aşıyordu → 0,02 sn); yıllık özet 16 sn → 1 sn, tahsilat
  radarı 4 sn → 0,5 sn. Eksik dizinler eklendi (`sql/lumen_cekirdek/08_dizinler.sql`).
- **Cari ekstresi:** çok hareketli caride PDF bellek yetmediği için açılmıyordu; artık son
  500 hareketi içerir, öncekiler "Devir" satırında toplanır (bakiye değişmez, tamamı Excel'de).
  Excel çıktısı 30 sn sınırına takılıp açılmıyordu → 1 sn.
- **Ürün ligi Excel'i** (20.000 ürün) 23 sn → 2,5 sn.
- **Takılan sorgu:** web isteğinde tek sorgu en fazla 100 sn sürer (`LUMEN_SORGU_SINIRI`);
  önceden uzun bir rapor kullanıcının oturumunu dakikalarca kilitleyebiliyordu.

### 📖 Kullanım kılavuzu

- `docs/kullanim/KILAVUZ.md`: giriş ve yetkiler, tanımlar, Excel'den aktarma, faturalar,
  kasa/banka, çekler, cari ve stok, siparişler, raporlar, yedekleme, sık sorulanlar.

### ✨ Yeni

- **Kasa/banka açılış fişi** artık ekrandan kesilebilir (yalnız yönetici): LOGO'suz yeni
  kurulumda kasa ve bankadaki devir parası girilir.

### 🐛 Düzeltmeler

- Kullanıcı formunda parola ipucu "en az 4 karakter" diyordu; kural 8 karakterdir.

- **Ürün ligi (son 30 gün):** veritabanı sunucusunun saat dilimi uygulamadan farklıysa gece
  yarısından sonra bugünün satışları görünmüyordu; artık uygulamanın takvimiyle hesaplanır.
  Demo veritabanı da İstanbul saatine ayarlandı.

## [1.3.0-beta.4] — 2026-10-21 (ÖN SÜRÜM — Bağımsız Lumen BETA)

> ⚠️ **Ön sürümdür (beta).** Üretim için kararlı **v1.2.2**'yi kullanın. Bu ön sürüm v1.2.2'deki
> güvenlik bakımını içerir.

### 🔒 Güvenlik denetimi (kararlıya geçiş öncesi)

v1.3.0'dan önce yeni ekranlar üç ayrı inceleyiciyle denetlendi; bulunanların hepsi
düzeltildi ve `tests/akis/guvenlik.php` ile her biri otomatik olarak yeniden denenir.

- **Özel cari kısıtı:** gizli carinin faturası ve kasa fişi iptal edilebiliyordu; stok
  kartında gizli carinin adı, API çek listesinde çekleri görünüyordu; çek giriş/ciro/kendi
  çek ve yazdırma görünümü (`fis_goruntule`) kısıtı uygulamıyordu.
- **Sipariş bütünlüğü:** depo personeli sayım/üretim belgesiyle siparişi "teslim edildi"
  gösterebiliyordu; aynı sipariş satırı bir formda iki kez gönderilince siparişten fazlası
  faturalanabiliyor, iptal siparişi bozuk bırakıyordu; ilgisiz bir belge "kaynak" gösterilerek
  başka bir belgenin iptali engellenebiliyordu; sipariş satırı ekranından kesilmiş bir
  faturaya satır eklenebiliyordu; teslim edilmiş satırın miktarı teslim edilenin altına
  düşürülebiliyordu.
- **Kur ve tarih:** dövizli belgede kur serbestçe yazılabiliyordu (artık tanımlı kurdan en
  fazla %10 sapma, yönetici hariç); 1990 gibi makul olmayan tarihler kabul ediliyordu.
  **Dönem kilidi** (Tanımlar → Firma): kapanan döneme belge/fiş girilmez, iptal edilmez.
- **Depo personeli:** üretimde fiyat/maliyet görüp girebiliyor, cari bağlayabiliyordu.
  Miktar görme yetkisi olmayan kullanıcı belge ürün aramasında miktar görüyordu.
- **Kasa/banka:** açılış fişini yalnız yönetici iptal edebilir; dövizli hesapla TL fiş kesilmez.
- **Excel'den aktar:** sağda unutulmuş tek bir hücre belleği tüketip ekranı kilitliyordu;
  onaylanmayan yüklemeler sunucuda kalıyordu.
- **Yedekleme:** günlük otomatik yedek kullanıcının oturumunu kilitliyor, başarısız olunca her
  istekte yeniden deneniyordu; geri yükleme eski "beni hatırla"/API belirteçlerini geri
  getiriyordu (artık herkes yeniden girer); yedek klasörü web klasörüne ayarlanabiliyordu;
  yedekler herkesçe okunabilir izinle yazılıyordu. Gece görevi için `scripts/yedek_al.php`.
- **Modül koruması:** büyük/küçük harf farkıyla (Windows/IIS) atlatılabiliyordu; bağımsız
  moda ait modüller ayrıca kendi içlerinde mod denetimi yapar.
- **Diğer:** LOGO uyumlu görünümlerde yeni kaydın kimliği oturum bağlamından okunur
  (`@@IDENTITY` yerine); stok KDV'si yalnız VAT güncellemesinde değişmiyordu; LOGO'dan
  aktarımda başka firmanın kullanıcıları pasif gelir; kurulumda sunucu/veritabanı adı denetlenir;
  `includes/`, `web.config` ve `.lumen.gz` dosyaları tarayıcıdan açılmaz.

### Yeni

- **Kabul testi paketi** (yapay zekâ ajanı ya da insan çalıştırır): `bash tests/kabul/calistir.sh`
  iki modu kurar, bütün testleri, "iş günü" senaryosunu ve sunucu denetimini koşar, rapor yazar.
  GitHub'da `Kabul testi` iş akışı (her sürüm etiketinde kendiliğinden). Gerçek kurulumu dışarıdan
  denetleme: `php tests/kabul/sunucu_denetimi.php <adres>`. Rehber: `docs/test/KABUL_TESTI.md`, `AGENTS.md`.

## [1.3.0-beta.3] — 2026-10-21 (ÖN SÜRÜM — Bağımsız Lumen BETA)

> ⚠️ **Ön sürümdür (beta).** Üretim için kararlı **v1.2.1**'i kullanın. Bu ön sürüm
> v1.2.1'deki güvenlik bakımını içerir.

### 🔒 Güvenlik

- **PHP2Shell (CVE-2026-109618, CVE-2026-109619)** — v1.2.1 ile aynı: PHP'nin kendisinde
  kimlik doğrulamasız uzaktan kod çalıştırma açıkları (Lumen kodunda değil). Sunucunuzda en
  az **PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12** olmalı; güncelleyene kadar `php.ini`'de
  `session.upload_progress.enabled=0`.
- Lumen yamasız PHP'yi **kurulum sihirbazında**, **yönetici ana sayfasında** ve **Sistem ve
  Yedek** ekranında uyarır (PHP sürümü de görünür).
- `web.config` (IIS): istek gövdesi 32 MB; URL, sorgu dizesi ve başlık sınırları.
  `.htaccess` (Apache): `LimitRequestBody` 32 MB; mod_php'de yükleme ilerlemesi kapalı.
- Demo imajı yamasız PHP ile derlenmez; `kur.sh` taban imajı her kurulumda yeniler.

## [1.3.0-beta.2] — 2026-10-19 (ÖN SÜRÜM — Bağımsız Lumen BETA)

> ⚠️ **Ön sürümdür (beta).** Üretim için kararlı **v1.2.0**'ı kullanın.
> Yükseltme (beta.1'den ya da v1.2.0'dan): `php scripts/sema_guncelle.php`.

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
