# Trendyol sipariş bağlantısı

Trendyol bağlantısı yalnız sipariş paketlerini **okur ve kontrol kutusuna alır**.
Sipariş oluşturma, eşleme, fiyat/KDV denetimi ve muhasebeye aktarım mevcut kullanıcı
onayıyla yapılır. Trendyol'a durum, stok, fiyat, fatura veya kargo bilgisi gönderilmez.

## Bağlantı ve çalışma

- Kanal türü Trendyol, kanal aktif olmalıdır. Yönetim için M33 ve M16 birlikte gerekir.
- Test (`stage`) ve üretim (`prod`) ayrı ortamlardır. Kullanıcı kendi satıcı numarası,
  API anahtarı ve gizli anahtarını girer. Test varsayılandır; ortam/satıcı değişiminde
  iki anahtar yeniden girilir. Formlar saklanan sırları geri göstermez.
- Ayarı kaydetmek veya sayfayı açmak API isteği yapmaz. Kullanıcı tarih aralığını
  seçip alımı başlatır. Ekran açıkken sayfalar otomatik alınabilir; kapatıldığında
  ilerleme SQL'de kalır ve aynı alım sürdürülebilir. Zamanlanmış arka plan işçisi
  bu sürümün kapsamında değildir.
- Tarih aralığı son üç ay içinde ve en fazla 14 gündür. İstanbul zamanları epoch
  milisaniyeye çevrilir. Başlangıç filtreleri alım boyunca değişmez; `nextCursor`
  aynen saklanır. Durum filtresi uygulanmaz; iptal gibi güncellemeler de kutuya gelir.
- İstekler en az 5 saniye arayla, sayfa başına 10 paket ve 1 MB sınırıyla yapılır.
  Her çağrı en fazla bir sayfa alır. Boş son sayfa da denetim iziyle saklanır.
- 429, servis hatası veya kesintide kalıcı bekleme ve en fazla 5 otomatik deneme
  vardır. Yetkilendirme/biçim hatası otomatik ilerlemeyi durdurur. Yeniden deneme
  mevcut cursor ve aynı filtrelerle yapılır; kullanıcının yeniden deneme eylemi
  sunucudaki bekleme süresini kaldırmaz.
- Devam bilgisi döngüsü, farklı satıcı, eksik paket kimliği veya kaydetme hatasında
  sayfa ilerletilmez. Ham yanıt, kutu kayıtları ve devam bilgisi aynı transaction'da
  kaydedilir. SQL hatasında tamamı geri alınır. Ayar değişimi/durdurma sırasında
  gelmiş eski ağ yanıtı kaydedilmez. Eşzamanlı ağ çağrısı 60 saniyelik lease ile
  sınırlandırılır; ağ beklerken SQL transaction'ı açık tutulmaz.

## Sırların yedeklenmesi

API bilgileri AES-256-GCM ile firma ve kanala bağlanarak şifrelenir. Yalnız sabit
Trendyol HTTPS adreslerine Basic Authorization başlığıyla gönderilir; yönlendirme
izlenmez. Başlıklar ve servis hata gövdeleri günlük/denetim izine kaydedilmez.

SQL yedeğine ek olarak `database/eticaret-master.key` dosyasını ayrıca güvenli
biçimde yedekleyin. Özel konum için `LUMEN_ETICARET_ANAHTAR_DOSYASI` kullanılabilir.
Bu dosya EVDS ana anahtarından ayrıdır. Başka sunucuya dönüşte aynı dosya
geri yüklenmezse kullanıcı bağlantı anahtarlarını yeniden girmelidir. Depoya,
destek kaydına veya ekran görüntüsüne gerçek anahtar eklemeyin.

## Doğrulama sınırı

`tests/akis/trendyol.php` yalnız sentetik taşıyıcı kullanır; dış API'ye bağlanmaz.
İki modda devam etme, tekrar, iptal güncellemesi, 429/401, eşzamanlı alım, bağlantı
değişimi ve sayfa yazımında hata sınanır. Gerçek API erişimi ve gerçek LOGO
kurulumundaki kabul ayrıca yetkilendirilmiş pilot gerektirir.

Resmî kaynaklar (26.10.2026):

- [Sipariş akışı ve cursor kuralları](https://developers.trendyol.com/v2.0/docs/getshipmentpackagesstream)
- [Yetkilendirme ve User-Agent](https://developers.trendyol.com/v2.0/docs/authorization)
