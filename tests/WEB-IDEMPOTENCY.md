# Web sipariş başlığı — idempotency kabulü

Lumen'in mevcut istemcisi PHP web arayüzüdür; masaüstü uygulaması yoktur. API bütün siparişi tek istekte yazarken web TL/döviz akışı önce boş başlık açar, ürünleri sonra ayrı uçlarla ekler. Bu paket **başlık açma** tekrarını korur; sonradan satır ekleme/güncelleme veya sevk yarışı korunduğu iddiasında bulunmaz.

## Kullanıcı davranışı

- TL müşteri/mağaza bağlantısı GET'te yalnız onay formu açar. CSRF korumalı POST başlığı oluşturur; GET yenileme sipariş yazmaz. Mevcut fiş bağlantısı düzenleme ekranına yönlenmeye devam eder.
- Döviz müşteri modalı kur seçimini onay formuna taşır. Eski doğrudan POST, anahtar ve CSRF içermediğinden kaldırıldı. Kur ve not bu formda tamamlanır.
- İlk formda sunucuda `random_bytes(32)` ile 64 hex anahtar oluşturulur. Firma/dönem, kullanıcı, cari, depo ve web akışıyla bağlanır. Aynı bağlamda henüz sonuçlanmamış formun GET yenilemesi aynı anahtarı getirir.
- İlk gönderimde etkin içerik oturumda sabitlenir. İstek başarılı olmadan içeriği değiştirmek 409 verir; otomatik yeni anahtar üretilmez. Dövizde kurun tam değeri ve not geri yüklenir, alanlar salt okunur olur. TL fiyat seçimi gizli alanda aynen kalır.
- Başarılı POST `303` ile fişe gider. Aynı POST'un çift tıklama, yenileme veya kayıp yanıt sonrası tekrar gönderilmesi aynı fişe gider. Başarıdan sonra müşteri seçiminden yeniden açılan form yeni anahtar alır; meşru ikinci sipariş mümkündür.
- JS ilk submit sırasında yalnız submit butonunu kilitler; anahtar/CSRF/kur alanları gönderilir. Geri/ileri sayfa dönüşünde kilit açılır. JS kapalıyken sunucu anahtar koruması sürer. Ağ kopmasında tarayıcının aynı POST'u tekrar gönderme/geri dönüp aynı formu gönderme davranışı kullanılır; otomatik ağ döngüsü yoktur.

## Sunucu sınırı

Ortak `M_API_IDEMPOTENCY` tablosu, transaction kilidi ve receipt helper'ı kullanılır. `web_tl_baslik:v1`, `web_doviz_baslik:v1` ve mevcut `siparis_olustur:v1` kapsamları ayrıdır. Web gövdesinin parmak izi etkin form içeriği ve sunucu bağlamını içerir. Başlık, kesin fiş numarası ve receipt aynı transaction'da yazılır. Commit sonrası audit hatası başarı yönlendirmesini gizlemez. Commit sonucu belirsizse başarısız/hiç yazılmadı diye kesin bir mesaj verilmez.

Her istek mevcut kullanıcı modül/cari kontrollerini tekrar uygular; kullanıcı yetki cache'i bu kapılarda temizlenir. Firma/dönem önekleri doğrulanır. Anahtar eksik/bilinmiyorsa, kapsam değiştiyse veya oturum kaybolduysa POST 409 ile durur; başlıksız eski web POST'u kayıt açmaz. CSRF eksik/yanlış/array ise 403; bozuk ID/kur/not tipi ise 400. Eksik ledger veya kilit alınamaması 503'tür; sessiz eski kayıt yoluna dönülmez.

Oturum form haritası en çok 64 kayıttır. Yer açmak için yalnız tamamlanan form kaydı çıkarılır; 64 sonuçlanmamış formda yeni form açılmaz. SQL receipt silinmez. Form/CSRF/anahtar kullanıcı oturumu içinde tutulur; tarayıcı localStorage'ına bearer/parola yazılmaz. Oturum sona erdiğinde eski form otomatik yeni siparişe çevrilmez: önce sipariş listesinden sonuç kontrol edilir. Çok sunuculu ortamda ortak/güvenilir session saklama ve oturum değişikliklerinin korunması ayrıca kabul edilmelidir.

## Çalıştırılan kontroller

```sh
php tests/web-intent-regression.php
node tests/web-intent-js.test.cjs
python3 tests/web-regression.py
```

Web helper/oturum ve kapsam kontrolleri 23 assertion; JS submit guard 8 assertion (DOM double). HTTP runner geçici, boş docroot ve ayrı session dizini ile localhost PHP sunucusu açar, sonunda sonlandırır. Gerçek `siparis/fisekle.php` ve `doviz/fisekle.php` kodunu çalıştırır; üretim bootstrap/layout include'larını çıkarır, login/yetki/CSRF altyapısı ve PDO sonuçları sentetiktir. GET yazmama, POST/303, çift gönderim, kayıp commit ACK, receipt hatasında rollback, değişen içerik/owner, yeniden kontrol edilen izinler, eksik migration/kilit, tam kur/not korunması ve bozuk form alanları test edilir. Gerçek tarayıcı çizimi, Apache/IIS, gerçek SQL kilit yarışı ve gerçek LOGO şeması bununla doğrulanmaz.

## Yayın öncesi kalan kapı ve sonraki adım

Migration **uygulanmadı**; tablo yoksa yeni web kayıt kapısı 503 verir. Kod tek başına canlıya taşınmamalıdır. Önce [SQLSERVER-ACCEPTANCE.md](SQLSERVER-ACCEPTANCE.md) ortamı ve ayrı boş DB'de SQL idempotency kabulü gerekir. Gerçek LOGO şema/trigger fixture'ı, PHP 8.2+/pdo_sqlsrv ve web login/session/izinler ile başlık→ürün ekleme→yeniden deneme uçtan uca kabulü tamamlanmalıdır. Web post payload snapshot/session geri yükleme, session expiry ve dağıtık session senaryosu da bu kapıdadır.

Güvenlik işleri tamamen kapandı denemez: gerçek SQL/LOGO kabulü, bağımlılık audit'i, satır düzenleme/sevk yarışı ve mevcut web yazma uçlarının tamamının güvenlik denetimi kalır. En küçük uygun sonraki paket **izole SQL kabulünü tamamlamak**; ardından bağımsız Lumen DB için kullanıcı/tercih/taslak/entegrasyon işlem kaydı sahipliği ve veri taşıma sözleşmesini somutlaştırmaktır. Bu pakette bağımsız DB veya yeni uygulama kurulmadı.
