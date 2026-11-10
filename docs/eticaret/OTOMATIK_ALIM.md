# Otomatik sipariş alımı (zamanlanmış görev)

Trendyol ve Hepsiburada bağlantılarında alım, ekran açık olmadan da sürebilir. Sunucudaki
zamanlanmış görev yeni siparişleri ve paketleri **yalnız kontrol kutusuna** alır. Ürün
eşleme, tutar kontrolü ve satış siparişine aktarım her zamanki gibi kullanıcı onayındadır.
Pazaryerine durum, stok, fiyat veya fatura gönderilmez.

## Açma

1. Uygulama ve veritabanı yedeğinden sonra `php scripts/sema_guncelle.php` çalıştırın.
   Bağlantı ve alım tablolarına otomatik alım alanları eklenir. Mevcut bağlantılarda
   otomatik alım kapalı başlar.
2. Satış kanalları → **Bağlantı** ekranında **Otomatik alım** kutusunu işaretleyip
   bağlantıyı kaydedin. Kaydetmek M33 ve M16 yetkisi ister ve yarım kalan alımı durdurur.
3. Görevi sunucuda 15 dakikada bir çalışacak şekilde tanımlayın:

   ```
   schtasks /Create /SC MINUTE /MO 15 /TN "Lumen e-ticaret alim" /TR "C:\php\php.exe C:\inetpub\lumen\scripts\eticaret_al.php"
   ```

   Linux cron: `0,15,30,45 * * * * php /var/www/lumen/scripts/eticaret_al.php`

Görevi çalıştıran hesap Lumen'in bağlantı ayarlarını okuyabilmeli ve `database/`
klasörüne yazabilmelidir. Görev aynı anda tek kopya çalışsın diye
`database/eticaret_al.kilit` dosyasını kullanır. E-ticaret ana anahtarı
(`database/eticaret-master.key` ya da `LUMEN_ETICARET_ANAHTAR_DOSYASI`) web sunucusunun
kullandığı dosyayla aynı olmalıdır.

## Nasıl ilerler

- Her çalıştırma en fazla 240 saniye sürer (`--sure` ile 30–3300 sn). Süre dolarsa kalan
  iş bir sonraki çalıştırmada aynı aralıktan sürer. `--kanal=ID` yalnız bir kanalı çalıştırır.
- Kanalda yarım kalan alım, elle başlatılmış olsa da önce sürdürülür. Sonra son tamamlanan
  otomatik aralığın sonundan bugüne yeni aralık açılır. Aralıklar 10 dakika örtüşür;
  tekrar gelen kayıt kutuda yeni sipariş oluşturmaz. Son aralıktan 5 dakika geçmeden yeni
  aralık açılmaz.
- İlk otomatik alım son 24 saati kapsar. Daha eskisi için alım ekranından elle aralık alın.
- Trendyol aralığı en fazla 14 gün, Hepsiburada aralığı en fazla 24 saattir. Uzun boşluk
  art arda aralıklarla kapanır. Trendyol son üç aydan eski dönemi vermez. Görev bu kadar
  uzun süre çalışmadıysa o dönem alınamaz ve bu durum denetim izine kaydedilir.
- Sayfa aralığı, 429/servis hatasında bekleme ve yeniden deneme, kilit ve atomik kayıt
  elle alımla aynıdır ([Trendyol](TRENDYOL_BAGLANTI.md), [Hepsiburada](HEPSIBURADA_BAGLANTI.md)).
  Birden çok kanal varsa biri beklerken diğeri ilerler.
- Elle başlatılan alım otomatik alımın kaldığı yeri değiştirmez. Aynı satıcı hesabının
  anahtarları yenilenince kaldığı yer korunur; ortam veya satıcı değişirse sıfırlanır.

## Yetki ve hatalar

Görev, otomatik alımı açan (bağlantıyı son kaydeden) kişi adına çalışır. Kişi etkin
değilse veya M33/M16 yetkisi kaldırılmışsa alım yapılmaz. Yetkilendirme hatası (401/403),
geçersiz yanıt veya beş başarısız deneme alımı durdurur ve görev aynı isteği tekrarlamaz.

Bağlantı ekranı son kontrol zamanını, kutunun hangi ana kadar güncel olduğunu, görevin
bir saattir çalışmadığını ve son görev hatasını gösterir. Sorunu giderip bağlantıyı yeniden
kaydetmek ya da alım ekranında **Yeniden dene** demek otomatik alımı sürdürür.

Çıkış kodu: `0` sorun yok, `1` en az bir kanal müdahale bekliyor, `2` görev çalışamadı
(kilit dosyası, veritabanı). Beklenmeyen hatanın ayrıntısı yalnız sunucu günlüğüne yazılır.
API anahtarı, istek başlıkları ve servis hata gövdesi çıktıya, günlüğe veya ekrana girmez.

## Doğrulama sınırı

`tests/birim/eticaret_otomatik_test.php` aralık hesabını; `tests/akis/trendyol.php` ve
`tests/akis/hepsiburada.php` görevi iki modda sentetik taşıyıcıyla sınar: aralık ve
örtüşme, 24 saatlik dilimler, yetki ve etkin kullanıcı, 401 sonrası durma, süre dolunca
devam, elle alımla birlikte çalışma, bağlantı ekranı ve betiğin kilidi. Gerçek pazaryeri
hesabıyla ve gerçek Görev Zamanlayıcı kurulumunda pilot kabul ayrıca gerekir.
