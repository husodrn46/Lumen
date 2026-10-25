# E-ticaret Sipariş Kutusu (beta)

LOGO ve bağımsız Lumen modlarında aynı akış kullanılır. İlk sürüm dosyadan veri alır;
Trendyol, Hepsiburada, WooCommerce, Shopify ve site türleri yalnız kanal tanımıdır.
Gerçek pazaryeri bağlantısı, anahtar veya otomatik veri çekme yoktur.

## Kurulum ve kullanım

Uygulama ve veritabanı yedeğinden sonra `php scripts/sema_guncelle.php` çalıştırın.
Yeni `M_ET_*` tabloları ve M33 yetkisi iki modda ortak kurulur. Yönetici otomatik
M33 alır; diğer kullanıcıya yetki matrisinden verilir. Kanal yönetimi ayrıca M16,
satış siparişine aktarım M1, liste değerinden farklı fiyat/KDV kullanımı SP1 gerektirir.
Özel cari kısıtları kutu listesi, detay ve aktarımda uygulanır.

1. Ana sayfa → E-ticaret → Satış kanalları. Kanal adı/türü ve aktifliği tanımlayın.
   İsterseniz mevcut ortak “E-ticaret müşterileri” carisini seçin. Cari/depo/personel
   varsayılanlarını birlikte seçin veya boş bırakıp sipariş detayında tamamlayın.
2. CSV / JSON içe aktar ekranında kanalı ve dosyayı seçip **Önizle** deyin.
3. **Sipariş kutusuna al** yalnız ara tablolara yazar; satış siparişi oluşturmaz.
4. Kutudaki siparişi açın. SKU/barkod için stok kodu ve kaynak birimine uygun birim
   seçerek kanalın ürün eşlemesini kaydedin. Stok araması kod/ad ile yapılır.
5. Cari/depo/personel, adresler, miktar, kaynak fiyatı/KDV, Lumen fiyatı/KDV ve
   seçilen depo stoğunu inceleyin. **Seçimleri kaydet ve kontrol et** deyin.
6. Hazır kayıtta onay kutusunu işaretleyip **Satış siparişine aktar** deyin.
   Oluşan siparişin numarası/kimliği ve bağlantısı görünür. Aktarım anındaki
   ürün/fiyat/KDV/eşleme görüntüsü korunur; sonraki eşleme değişimi bu görüntüyü değiştirmez. Kaynak tarih kutuda korunur;
   satış siparişi mevcut Lumen akışındaki gibi aktarım günü açılır.

Ortak cari kartının müşteri/adres bilgileri değiştirilmez. Fatura ve teslimat
bilgileri, ham sipariş ve kaynak dosya kutuda korunur. Bu sürüm adresleri otomatik
fatura/kargo üretimine taşımaz. Sipariş kendi başına stok/cari hareketi oluşturmaz;
mevcut sipariş rezervasyon ayarı uygulanır. Stok kontrolü stok ayırma garantisi değildir.

## Dosya sözleşmesi v1

[JSON örneği](ornek.json) ve [CSV örneği](ornek.csv) bütünüyle sentetiktir.
Ekrandaki örnek indirme bağlantıları aynı dosyaları verir.

- UTF-8 (BOM kabul edilir), en fazla 1 MB ve dosyada 1–100 sipariş.
- JSON kökü `{"surum":1,"siparisler":[...]}`. Her siparişte `numara`, `tarih`
  (`YYYY-AA-GG`), `para_birimi` (`TRY`), `genel_toplam`, `musteri`, `fatura`,
  `teslimat` nesneleri ve `kalemler` listesi gerekir. `kimlik` yoksa numara kullanılır.
- Kalem: `sku` veya `barkod`, `ad`, `birim`, pozitif `miktar` ve `fiyat`, `kdv`,
  boolean `kdv_dahil`. Sipariş başına en fazla 200 kalem. Fiyat kaynak birimi başınadır;
  KDV dahil fiyat önce KDV hariç, sonra ana birim fiyatına çevrilir.
  Birim çarpanı `CONVFACT2 / CONVFACT1` üzerinden doğrulanır; MVP 1–100000 aralığını destekler.
- CSV ayracı `;`, ilk satır örnekteki başlıktır. Her satır bir kalemdir; aynı siparişin
  başlık bilgileri her satırda aynı olmalıdır. En fazla 3000 veri satırı kabul edilir.
  `kdv_dahil`: `1`/`0` veya `true`/`false`. Adres alanında satır sonu varsa CSV tırnakları kullanılır.
- Sayılarda nokta veya virgül ondalık ayracı kullanılabilir; binlik ayraç ve bilimsel
  gösterim kullanılmaz. Birim fiyat/miktar en fazla altı, KDV en fazla iki ondalık hanedir.
- `iskonto`, `kargo`, `masraf` alanları varsa sıfır olmalıdır. Desteklenmeyen tutarlar
  sessizce düşürülmez. Bütün satırlar ve toplam geçmeden aktarım açılmaz.
- Müşteri/fatura/teslimat nesnelerinin ek alanları JSON içinde kırpılmadan korunur.
  Kaynakta ek tutar alanları kullanılıyorsa önce ortak sözleşmeye dönüştürülmelidir;
  yalnız `genel_toplam` ve desteklenen kalem fiyatları aktarım hesabına girer.

## Durumlar ve tekrarlar

Yeni → Eşleme bekliyor / Hatalı → Hazır → Aktarıldı. Aktarım öncesi kayıt iptal
edilebilir. Kutu iptali satış siparişini iptal etmez; aktarılmış kayıt tekrar açılamaz.

Aynı kanal/harici kimlik ve kanal/numara/paket kimliği veritabanında benzersizdir; büyük/küçük harf
ve baştaki sıfırlar korunur. Paket kimliği olmayan eski dosyalar aynı şekilde çalışır.
Genel JSON'da `paket_kimligi` isteğe bağlıdır; paketli siparişlerde her kalemin
`harici_kimlik` değeri paketler arasında kalıcı olmalıdır. Ayrı paketlerde aynı
sipariş numarası kullanılabilir; daha önce aktarılmış harici kalem ikinci kez
aktarılamaz. Paketli ve paketsiz alım aynı sipariş için karıştırılamaz. Aynı içerik tekrarında mevcut kayıt tutulur. Değişmiş
kaynak, mevcut siparişi ezmeden denetim olayında ve kaynak dosyada saklanır. Kaynak
revizyonunu mevcut siparişe uygulama bu MVP'de yoktur. Değişmiş içerik hazır onayı
düşürür; denetim izini inceleyip orijinal kayıtla devam kutusunu açıkça onaylamadan
aktarım açılamaz. Kimliği/numarası olmayan hatalı kayıt kaynak dosyada ve alım özetinde
korunur; bağımsız bir kutu kaydı oluşturulamaz.

Eşleme veya kanal tanımı değişirse bekleyen hazır onaylar düşer. Cari/depo/personel,
ürün, fiyat veya stok değişimi aktarım öncesi tekrar kontrol edilir. Hata durumunda
hata mesajı ve deneme sayısı saklanır; **kontrol et** adımı sonrası tekrar denenir.
Aktarılmış siparişin tekrar gönderilmesi yeni sipariş oluşturmaz.

## Mimari ve test

CSV/JSON okuyucuları → ortak model → `M_ET_*` kutu → kullanıcı kontrolü → ortak
sipariş servisi. Gelecek kanal adaptörleri aynı modele veri sağlar; çekirdek kurallara
pazaryeri kodu eklenmez. `M_ET_SIPARIS` kilidi ve `M_API_IDEMPOTENCY` koruması, sipariş
ve sonuç kaydıyla aynı transaction içindedir. LOGO'da ORFICHE/ORFLINE; bağımsızda
mevcut LMV görünümleri/tetikleyicileri üzerinden LM_BELGE/LM_BELGE_SATIR yazılır.

Demo konteynerinde: `php tests/birim/calistir.php`, `php tests/akis/eticaret.php` ve
`LUMEN_TEST_SADECE=eticaret php tests/duman/tara.php`. Akışlar yalnız `LUMEN_*` demo/test
veritabanlarını kabul eder. Her iki mod GitHub CI'da çalışır. Atomiklik testinde demo
tetikleyicisiyle satış siparişi yazıldıktan sonra hata üretilir ve rollback doğrulanır.

Ham dosya/adresler müşteri bilgisi içerebilir; bu yüzden M33 yalnız ilgili personele
verilmelidir. Otomatik veri silme, kişisel veri saklama süresi yönetimi, gerçek API,
döviz, çok depoya bölme, otomatik cari, toplu aktarım, kargo, mutabakat, iade ve e-belge
ilk sürümün dışındadır.
