# Sipariş oluşturma: tekrar gönderim protokolü

Bu belge **yerel, yayımlanmamış** sunucu değişikliğini anlatır. Gerçek SQL/LOGO ve masaüstü istemci kabulü tamamlanmadı. Mevcut depoda masaüstü istemci kaynak kodu yok; incelenebilen sözleşme `api/siparis_olustur.php` endpoint'i, onun LOGO yazma akışı ve yanıtıdır. İstemciye bağlantı/entegrasyon uygulanmış sayılmaz.

## Anahtar ve istemci davranışı

POST `/api/siparis_olustur.php` isteğine isteğe bağlı `Idempotency-Key` header eklenir. İstemci **her yeni sipariş işlemi için** kriptografik UUIDv4 veya 32 rastgele byte'ın 64 hex gösterimini üretir. Hedef sunucu/bağlantı kimliğini, kullanıcı/firma/dönem bağlamını, anahtarı ve gönderilecek JSON gövdesinin değişmez snapshot'ını ilk gönderimden **önce** kendi bekleyen işlem kaydına yazar. Retry, timeout, yanıt kaybı veya yeniden giriş aynı pending işlem için **aynı anahtar + aynı gövde** kullanır. Retry sırasında yeni UUID üretmek korumayı bozar.

Ayrı meşru sipariş, cari/ürün/fiyat/tarih/tutar tamamen aynı olsa bile yeni işlem ve **yeni anahtar** demektir. Sunucu bunlardan tahmin ederek kayıtları birleştirmez. Anahtar token değildir, tek başına erişim sağlamaz; bearer, M1 ve güncel cari görünürlüğü her istekte yeniden kontrol edilir.

Header UUIDv4 veya 64 hex olmalı; whitespace, kısa değer, birleştirilmiş birden fazla header, UUID'nin başka sürümü reddedilir. Hex/UUID harf büyüklüğü normalize edilir. Regex, istemcinin gerçekten rastgele ürettiğini kanıtlamaz; CSPRNG kullanımı istemci sorumluluğudur.

**Eski istemci uyumluluğu:** header yoksa mevcut sipariş akışı ve yanıt yapısı korunur. Bu isteğe idempotency garantisi verilmez; otomatik yeniden gönderilmemeli. Header geçersizse 400, gerekli ledger migration yoksa 503; sunucu sessizce başlıksız moda geçmez. Gerçek istemci kaynakları bulunmadan onun bu protokole geçtiği söylenemez.

## İçerik ve sahiplik bağı

Makbuz anahtarı DB genelinde tekildir; kullanıcı/firma/dönem/eylem bağları makbuzda ayrıca tutulur. Aynı anahtarın başka kullanıcı, firma, dönem veya eylem için kullanımı 409 ile kapanır; ilk siparişin sonucu o kişiye verilmez. Token yenilemek aynı PERSONEL/firma/bağlam kaldığı sürece anahtarı değiştirmeyi gerektirmez. Firma/dönem config'i değişmişse otomatik yeni anahtara geçilmez; eski işlemin sonucu asıl bağlamında kontrol edilir.

Parmak izi, ayrıştırılmış **tüm JSON gövdesinden** üretilir; obje alan sırası normalize edilir, liste/satır sırası ve sayısal/metin tipleri korunur. Eksik alan ile açık default, `1` ile `"1"` veya `1.0` aynı kabul edilmez; gövde snapshot'ı değişmemeli. Fiyat/KDV/birim/iskonto veya ek alan değişikliği tamamlanmış anahtarla 409 üretir (normal girdi doğrulaması önce 400/422 de verebilir). İstemci retry sırasında güncel fiyatı tekrar okuyup gövdeyi değiştirmemeli. İlk kayıt sonucu ürün/fiyat tekrar sorgulanmadan döner; siparişin sonraki güncel durumu ayrıca okunmalıdır.

Body fingerprint v1 sunucunun PHP ile ayrıştırdığı JSON değerlerini kullanır; iç içe JSON obje/listelerin PHP array'e dönüştürülme sözleşmesi aynıdır. Hash sürümü ve kabul semantiği sonraki deploy'da keyfi değiştirilmemeli. Başarı kesinleşmeden validation/rollback ile reddedilen istekte makbuz kalmaz; anahtar henüz tamamlanmış bir siparişe bağlanmamıştır. İstemci yine aynı gövdeyle retry yapmalı; kullanıcı siparişi değiştiriyorsa bekleyen işlemi çözüp yeni işlem açmalıdır.

## Transaction ve yarış davranışı

1. Bearer/M1/cari erişimi ve temel girdi doğrulaması yapılır.
2. Tek SQL transaction başlar. Ledger şeması ve tek KEYHASH PK doğrulanır. `sys.sp_getapplock`, anahtar digest'i için DB genelinde Exclusive, Transaction-owned lock alır; timeout 5 saniyedir.
3. Mevcut makbuz varsa sahiplik/içerik kontrolü yapılır. Eşleşiyorsa transaction kapanır ve önceki başarılı JSON/200 döner; yeni başlık/satır veya audit çağrısı yoktur.
4. Makbuz yoksa cari/kalemler doğrulanır; mevcut LOGO başlık/satır/iskonto/toplam akışı çalışır. Başarı JSON'u ledger'a yazılır; **sipariş + makbuz aynı commit'te** kesinleşir. Kayıt öncesi pending satır veya bağımsız commit yoktur.
5. Rollback sipariş ve makbuzu birlikte geri alır; key lock transaction sonunda bırakılır. Bekleyen aynı anahtar ilk commit'i görüp replay eder, ilk rollback olursa kendisi ilk başarılı işlem olabilir.

Lock timeout/cancel/deadlock 503 ve Retry-After: 1 üretir. Aynı key/body ile retry gerekir. Commit sonucu belirsizse 500 mesajı hiçbir kayıt yapılmadığını iddia etmez; keyed istemci aynı işlemle sorgular. Commit olmuşsa saklanan sonuç gelir, rollback olmuşsa sipariş yeniden güvenle yazılabilir. Ağ/client timeout'u sonrası da aynı yöntem geçerlidir. M1/cari erişimi geri alınmışsa replay 403/404 verebilir; yeni key ile kısıt aşılmaya çalışılmaz.

Ledger'da raw token veya ham istek gövdesi tutulmaz; key/body SHA-256, owner/firma/dönem/kapsam ve mevcut yanıt saklanır. Anahtarlar otomatik expire/silinmez: eski retry'ın yeni sipariş yaratmasını önlemek için makbuz korunur. Retention/archiving ayrıca planlanır; tombstone olmadan key silmek duplicate riskini geri getirir. Makbuz DB'si taşıma/restore ve LOGO kaydı aynı tutarlılık sınırında korunmalı; sadece ledger restore edip LOGO'yu farklı tarihe geri almak güvenceyi bozar.

## Migration ve yetki — uygulanmadı

`sql/m_api_idempotency.sql`, mevcut DB'de dbo.M_API_IDEMPOTENCY ekleyen, veri silmeyen yerel migration'dır. Doğru mevcut şemada tekrar çalışabilir; uyumsuz şemayı otomatik dönüştürmez. Runtime CREATE/ALTER yapmaz. Bu dosya hiçbir gerçek DB'ye uygulanmadı.

Yetkili yönetici önce şema/PK/datatypes ve least-privilege erişimi incelemeli. Keyed API hesabı bu tabloda metadata/SELECT/INSERT ve Transaction-owned `sys.sp_getapplock` çağrısına yetkili olmalı; token/cari/LOGO mevcut izinleri de geçerli kalır. Migration CREATE TABLE/constraint yetkisi ayrı yönetici işidir; API hesabına global yetki gerekmez. Grant veya yeni credential uygulanmadı. Kurulum betikleri listesi 14 SQL oldu; yeni kurulum gerekli ledger sütunlarını da doğrular.

Yayın sırası daha sonra ayrıca onaylanmalı: migration ve bütün yazan API örnekleri hazır/tek uyumlu sürüm olduktan sonra istemci keyed retry'ı açmalı. Eski API sürümü header'ı yok sayar; load balancer'da eski/yeni sürüm karışımı korumayı bozar. Rollback'te keyed retry güvenli bir sunucuya yönlendirilmeli veya durdurulmalı; header'ı kaldırıp sessizce yeniden POST yapılmamalı. Bu görevde yayın yapılmadı.

## Test kanıtı ve çalıştırma

```sh
php tests/idempotency-regression.php
python3 tests/http-regression.py
```

Stateful fake PDO suite gerçek endpoint gövdesini ardışık isteklerde çalıştırır: aynı key replay, lost-response simülasyonu, iki meşru aynı içerikli sipariş, changed body/owner/company/period/scope reddi, permission/cari revocation, satır/receipt rollback, commit öncesi hata ve commit olmuşken acknowledgement kaybı simülasyonu, lock timeout, eksik migration ve eski header'sız davranış. Fake transaction snapshot, SQL rollback kanıtı değildir; aynı-key race'in gerçek motor kanıtı da değildir. HTTP fixture gerçek header/body aktarımını sınar; ledger/PDO gerçek değildir.

Gerçek SQL kabulü için **ayrı boş localhost LumenTest_* DB**:

```sh
php tests/sqlserver-idempotency.php
```

PHP 8.2/8.3 + pdo_sqlsrv ve `tests/SQLSERVER-ACCEPTANCE.md` ortam değişkenleri/yazma onayı gereklidir. Bu betik boş DB guard'ından sonra sentetik SQL fixture ve yerel ledger migration'ı yükler; 20 paralel aynı-key worker, gerçek rollback, commit sonrası yanıtsız worker, 6 saniye lock holder/5 saniye timeout, ardından replay, farklı key ve farklı owner/body/period reddini sınar. Test DB'yi silmez; tekrar çalıştırma için yeni boş DB gerekir. `sp_getapplock` yetkisi ve SQL driver rowset/rowCount davranışı bu kapıda doğrulanmalıdır.

Bu test **hazır ama çalıştırılmadı**: Forge PHP 8.1.34, pdo_sqlsrv yok; çıkış 2. Gerçek LOGO 95+ INSERT kolonları, trigger/NOCOUNT, stok/iskonto, HTTP proxy ve masaüstü pending-operation akışı ayrı kabul ister. SQLite/FreeTDS veya sentetik PDO geçişi bu kabulün yerine sayılmaz.

SQL kilidi Transaction içinde alınır ve commit/rollback ile bırakılır; aynı resource farklı DB'lerde farklı kilittir. Protokol farklı DB/kurulumlar arasında ortak dedupe garantisi vermez. Pending işlem başka sunucuya sessizce yönlendirilmemeli. Bu davranış [Microsoft sp_getapplock belgeleri](https://learn.microsoft.com/en-us/sql/relational-databases/system-stored-procedures/sp-getapplock-transact-sql?view=sql-server-ver17) ile uyumludur; gerçek sürücü/motor kabulünün yerine belge doğrulaması sayılmaz.

## Mevcut web istemcisi

Lumen bugün yalnız web arayüzüdür. Web boş başlık açma akışı ortak receipt tablosunu ayrı `web_tl_baslik:v1` / `web_doviz_baslik:v1` kapsamıyla kullanır; API sipariş gövdesine veya bearer auth akışına çevrilmez. Uygulanan form/oturum/CSRF sözleşmesi ve kalan kabul sınırı: [WEB-IDEMPOTENCY.md](WEB-IDEMPOTENCY.md).
