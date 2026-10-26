# Hepsiburada paket bağlantısı

Hepsiburada'da **hazırlanmış açık paketler** GET ile okunup mevcut sipariş
kutusuna alınır. Kullanıcı ürün/cari/depo/fiyat/KDV kontrollerini tamamlamadan
Lumen satış siparişi oluşturulmaz. LOGO ve bağımsız modda aynı akış kullanılır.

## Kapsam

Bağlantı `/packages/merchantid/{merchantId}` servisini kullanır. Henüz
paketlenmemiş veya ödemesi beklenen siparişler, ayrı iptal/iade/teslimat servisleri,
paket oluşturma, fatura, stok ve fiyat gönderme bu sürümde yoktur. Bir paketin
sonraki listede görünmemesi iptal, teslimat veya tamamlanma kanıtı sayılmaz;
mevcut kayıt otomatik onaylanmaz veya iptal edilmez. Satış siparişine aktarım
öncesinde Hepsiburada panelinden paketin güncelliği kontrol edilmelidir.

## Bağlantı

Satış kanalı Hepsiburada ve aktif olmalıdır. Bağlantı/alım yönetimi için M33 ve
M16 birlikte gerekir. Kullanıcı kendi ortamına ait şu bilgileri girer:

- Merchant ID: satıcıya verilen UUID.
- API kullanıcı adı ve servis anahtarı/şifre: Basic Authentication çifti.
- User-Agent / entegratör adı: hesap için tanımlanan istemci bilgisi. Trendyol'un
  `SelfIntegration` değeri Hepsiburada'ya otomatik taşınmaz.
- Test (`stage`) veya üretim (`prod`) ortamı. Varsayılan testtir; bilgiler
  ortamlar arasında aynı varsayılmaz. Lumen servis hesabı açmaz veya anahtar üretmez.

Kaydetmek veya sayfayı açmak ağ isteği göndermez. Kullanıcı açıkça alımı başlatır.
Her çağrı en fazla bir sayfa alır; ekran açıkken devam eder, kapatılırsa SQL'deki
ilerlemeden sürdürülebilir. Zamanlanmış arka plan işçisi kurulmaz.

## Sayfalama ve kontroller

- Tarihler İstanbul saatiyle seçilir. `begindate/enddate` aralığı en fazla 24
  saattir. Servis daha uzun bitişi yok sayabildiği için Lumen uzun aralığı reddeder.
- `limit=10` ve `offset=0` ile başlanır. `limit`, `offset`, `totalcount`,
  `pagecount` yanıt başlıkları doğrulanır. Gövde JSON paket listesidir.
- Devam offset'i ve ilk toplam SQL'de tutulur. Filtre değiştirilmez. Eksik/çelişkili
  başlıkta, değişen toplamda veya önceki paket sayfasının yinelenmesinde ilerlenmez;
  kullanıcı aynı zaman aralığını yeniden başlatabilir. Offset servisi gerçek bir
  anlık görüntü garantisi vermez; aynı toplam içinde üyelik değişikliği her durumda
  saptanamaz. Örtüşen yeniden alımlar önerilir; benzersiz anahtarlar tekrarları önler.
- Her kalemin `merchantId` değeri bağlantıyla eşleşmelidir. Kimliksiz bir paket,
  kalem veya sipariş sayfanın ilerlemesini durdurur. Finansal/birim/KDV hataları
  mevcut adaptörün kurallarıyla kutuda incelemeye bırakılır; ham veri korunur.
- İstekler Lumen tarafından en az 5 saniye arayla gönderilir. 429 ve geçici servis
  hataları kalıcı bekleme ile en fazla 5 kez denenir. 401/403 otomatik alımı durdurur.
- Ham gövde, kutu kayıtları ve offset aynı transaction'da kaydedilir; SQL hatasında
  tamamı geri alınır. Ağ sırasında SQL/PHP oturum kilidi tutulmaz. Kanal türü veya
  bağlantı değişirse eski sırlar başka adaptöre gönderilmez, geç gelen yanıt ilerlemez.

## Sırlar ve kurtarma

Kullanıcı adı ve servis anahtarı firma/kanala bağlı AES-256-GCM ile saklanır;
forma geri basılmaz, URL'ye veya günlüklere eklenmez. Yalnız sabit
`oms-external-sit.hepsiburada.com` ve `oms-external.hepsiburada.com` HTTPS
adreslerine GET yapılır; yönlendirmeler izlenmez.

SQL yedeğine ek olarak `database/eticaret-master.key` dosyasını güvenli biçimde
yedekleyin (özel yol: `LUMEN_ETICARET_ANAHTAR_DOSYASI`). Bu dosya olmadan sırlar
geri açılamaz; bağlantı bilgileri yeniden girilmelidir. Mevcut Trendyol bağlantı
şeması otomatik yükseltilir; şifreli veri, sürüm ve eski cursor korunur.

## Test sınırı ve resmî kaynaklar

`tests/akis/hepsiburada.php` dış API çağrısı yapmaz; sentetik taşıyıcı ve demo
verisi kullanır. Gerçek hesap/servis erişimi ve gerçek LOGO kurulumu ayrıca pilot
kabul gerektirir. Portalın bazı doğrudan belge istekleri erişim filtresine takıldığı
için yayımlanmış resmî rehber ve uç nokta sözleşmesi esas alınmıştır; canlı erişim
doğrulanmış sayılmaz.

26.10.2026 tarihinde incelenen kaynaklar:

- [Sipariş entegrasyonu önemli bilgiler](https://developers.hepsiburada.com/tr/companies/hepsiburada?category=baslangic&guide=siparis-entegrasyonu-onemli-bilgiler&product=siparis-olusturma-entegrasyonu&version=v1.0&view=guide)
- [Satıcıya ait paketleri listeleme](https://developers.hepsiburada.com/tr/companies/hepsiburada?category=siparis-yonetimi&product=siparis-olusturma-entegrasyonu&version=v1.0&op=Get__packages_merchantid_merchantId&view=endpoint)
- [Servis anahtarı görüntüleme](https://developers.hepsiburada.com/tr/companies/hepsiburada?category=baslangic&guide=entegratore-servis-anahtari-eklemegoruntuleme&product=api-authentication&version=v1.0&view=guide)
