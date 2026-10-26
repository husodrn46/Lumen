# Trendyol ve Hepsiburada adaptörleri

İnceleme tarihi: **25 Ekim 2026**. Yalnız resmî geliştirici belgeleri kullanıldı.
Bu sürüm ağdan sipariş çekmez; belgelenmiş API yanıtını JSON dosyası olarak okur.
Gerçek hesap, erişim anahtarı, test siparişi oluşturma veya pazaryerine yazma yoktur.

## Desteklenen girişler

| Kanal | Seçilecek dosya biçimi | Beklenen yanıt |
|---|---|---|
| Trendyol | Trendyol sipariş paketleri v2 JSON | `content` paket listesi; sayfalı ve stream yanıtları |
| Hepsiburada | Hepsiburada paket listesi JSON | Satıcıya ait paketlerin JSON listesi |

Örnekler yalnız sentetiktir: [Trendyol](trendyol_v2.json), [Hepsiburada](hepsiburada_paket.json).
İçe aktarma ekranında biçimi ve uygun kanal türünü seçin. Dosya önizlenir, kutuya
alınır ve mevcut eşleme/onay akışıyla satış siparişine dönüşür. Boş API sayfası
normal karşılanır. Sayfalardaki eksik kayıtlar tamamlanmış varsayılmaz; her dosya
kendi içeriğiyle işlenir, otomatik sonraki sayfa isteği yapılmaz.

## Trendyol

Resmî kaynaklar: [Sipariş paketleri](https://developers.trendyol.com/v2.0/docs/get-order-packages-getshipmentpackages),
[stream alan açıklamaları](https://developers.trendyol.com/v2.0/docs/getshipmentpackagesstream),
[değişiklik günlüğü](https://developers.trendyol.com/changelog/changelog).

- `orderNumber` ana sipariş, `shipmentPackageId` paket, `lineId` kalem kimliğidir.
- `stockCode`/`barcode` ürün eşlemesine; `vatRate` KDV oranına gider.
- Belgelenmiş indirimli `lineUnitPrice` aktarılır. Brüt, satıcı/platform indirimi
  ve paket toplamı karşılaştırılır; indirim yeniden uygulanmaz. Bileşenler detayda görünür.
- Yalnız `Created` ve `Picking` paket/kalemleri hazırlanır. Ödeme bekleyen, iptal,
  bölünmüş, tamamlanmış ve bilinmeyen durumlar aktarımı engeller.
- `originPackageIds` eski/yeni paket ilişkisini korur. Aktarılmış kalem tekrar
  satılamaz. İhracat/aracılık modeli bekletilir.

Gelecek ağ adaptörü için: 15 Ekim 2026 sonrasında belirtilen `/v2/orders` ucu
kullanılmalı; eski `/orders` yoluna dayanılmamalı. Stream sayfalama `nextCursor`
ve `hasMore` kullanır. Zaman aralığı/sayfalama ve limitler canlı bağlantı
uygulamasından önce aynı resmî belgelerden tekrar doğrulanmalıdır.

## Hepsiburada

Resmî kaynaklar: [Sipariş entegrasyonu açıklamaları](https://developers.hepsiburada.com/tr/companies/hepsiburada?category=baslangic&guide=siparis-entegrasyonu-onemli-bilgiler&product=siparis-olusturma-entegrasyonu&version=v1.0&view=guide),
[paket listeleme](https://developers.hepsiburada.com/tr/companies/hepsiburada?category=siparis-yonetimi&product=siparis-olusturma-entegrasyonu&version=v1.0&op=Get__packages_merchantid_merchantId&view=endpoint).

- `packageNumber` paket, `items.orderNumber` sipariş, `lineItemId` kalem kimliğidir.
- `merchantSku`/`productBarcode` eşlemeye gider; HB ürün kodu EAN yerine kullanılmaz.
- `vat` KDV tutarıdır, oran değildir; `vatRate` oran olarak alınır. Birim, satır,
  paket toplamı ve KDV birlikte doğrulanır.
- Yalnız `Open` ve `Packaged` desteklenir. Birden fazla sipariş içeren paket,
  satıcı/müşteri tutar farkı veya ayrı indirim faturası gerektiren kayıt bekletilir.

Gelecek bağlantı için: portal test uçları `-sit` içerir; test ve canlı ortamlar
ayrıdır. Paket hizmeti Basic Auth ve User-Agent tanımlar, limit/offset ile okunur.
Bu aşamada hiçbirine istek gönderilmez. Ödeme bekleyen sipariş yanıtı bu paket
adaptörüne verilmemeli; ayrı bir adaptör gerektirir.

## Ortak korumalar ve sınırlar

- Aynı sipariş farklı paketlerle gelebilir; kanal/sipariş/paket ve kanal/harici
  kimlik benzersizliği korunur. Paketli/paketsiz dosyalar karıştırılmaz.
- Kaynak iptal/ödeme engeli “orijinal kayıtla devam” seçeneğiyle kaldırılamaz.
- Trendyol `lastModifiedDate` UTC'ye çevrilir. Hepsiburada'da sağlanmışsa
  `lastStatusUpdateDate` kullanılır. Eski zamanlı yanıt denetimde saklanır ama
  mevcut durumu geri almaz. Engel ancak daha yeni doğrulanmış zamanla kalkar;
  güncelleme zamanı yoksa kendiliğinden kaldırılmaz.
- Aktarımdan sonra gelen iptal uyarılır; Lumen siparişi otomatik iptal edilmez.
- Gerçek API bağlantısı, kimlik bilgisi saklama, webhook, zamanlama, iade,
  kargo/komisyon mutabakatı ve Hepsiburada indirim/fatura farklarının otomasyonu
  bu adımda yoktur. TRY, ADET ve mevcut 1 MB/100 paket sınırları geçerlidir.
