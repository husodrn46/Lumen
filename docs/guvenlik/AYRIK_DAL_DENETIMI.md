# Ayrık güvenlik dalı denetimi

Denetim tarihi: 24 Ekim 2026  
Dal: `origin/codex/lumen-startup-safety`  
İncelenen son commit: `43a11b6`  
Ortak ata: `636bae4`

Bu dal, güncel `main` dalından 25 commit geride ve 13 commit ileridedir. Doğrudan
birleştirilmemiştir: sipariş akışında sonradan eklenen sevk edilmiş satır ve müşteri
yetkisi korumalarını geri alabilecek eski uygulama kodları içerir. Her commit ayrı
değerlendirilmiş, hâlâ gerekli olan değişiklikler güncel kod üzerine taşınmıştır.

## Karar özeti

| Commit | Karar | Gerekçe / takip |
| --- | --- | --- |
| `0417ec0` | Kısmen taşındı | Türü işaretli API token özeti, kesin Bearer ayrıştırma, sıkı JSON gövdesi ve çıkış ucu güncel kod üzerine taşındı. Sipariş idempotency işi #30 olarak ayrıldı. Eski sipariş uygulaması ve kullanılmayan web-intent katmanı taşınmadı. |
| `04fbb06` | Taşınmadı | Artık bulunmayan deneme iş akışının IPv4 bağlantı ayarıdır; mevcut demo CI servis ağı kullanır. |
| `a0e7425` | Taşınmadı | İsteğe bağlı veritabanı favorileri ürün özelliğidir; güvenlik düzeltmesi değildir ve artık kullanılmayan `database/lumen/migrations` düzenine bağlıdır. |
| `816772a` | Taşınmadı | Yukarıdaki favori özelliğine bağlı tek seferlik aktarım aracıdır. Özellik kararı verilirse güncel `sql/` şemasıyla yeniden tasarlanmalıdır. |
| `0a6c3d5` | Taşınmadı | Yalnız deneysel sıkı TLS test düzeneğidir; üretim kodunu değiştirmez. |
| `c92f795` | Taşınmadı | Yukarıdaki TLS düzeneğinin hata tanılamasıdır. |
| `eae96ad` | Taşınmadı | Yerel ODBC deneyi için hash'li CA dizinleri oluşturur. |
| `95906de` | Taşınmadı | Yalnız deneysel TLS hata sınıflandırmasını değiştirir. |
| `8f51779` | Taşınmadı | Taşınmayan favori yarış testi için sınırlı yeniden denemedir. |
| `fac14b5` | Taşınmadı | Linux mount namespace gerektiren TLS test izolasyonudur; mevcut container CI ile uyumlu değildir. |
| `0d2dfab` | Taşınmadı | Aynı deneysel TLS düzeneğinin aşama günlüğüdür. |
| `897eaef` | Taşınmadı | Aynı deneysel TLS düzeneğinde isteğe bağlı CA takma adını ele alır. |
| `43a11b6` | Taşınmadı | Aynı deneysel TLS düzeneğinde varsayılan CA dosyasını yalnız namespace içinde gizler. |

## Güvenlik sonucu

- Veritabanında saklanan çıplak SHA-256 token özeti daha önce doğrudan Bearer token
  olarak yeniden kullanılabiliyordu. Saklama biçimi `sha256:<özet>` yapılarak bu
  tekrar kullanım kapatıldı.
- Var olan 64 haneli özetler şema güncellemesi sırasında işaretlenir. Eski düz token
  kayıtları ayırt edilemediğinden geçersiz kalır ve kullanıcı yeniden giriş yapar.
- Token tablosu oluşturulamıyor veya şeması yetersizse giriş artık açık kalmak yerine
  güvenli biçimde `503` döndürür.
- Sipariş oluşturmanın ağ/istemci tekrarlarında yinelenmesini önleyecek idempotency
  çalışması ayrı ve izlenebilir kapsam olarak #30'a taşındı.

Bu değişiklik birleştirilip kontroller geçtiğinde ayrık dalın korunması gereken başka
üretim değişikliği kalmaz; dal arşivlenebilir veya silinebilir.
